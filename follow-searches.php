<?php

use Marquine\Etl\Container;
use Marquine\Etl\Etl;
use Marquine\Etl\Row;

require 'vendor/autoload.php';
require 'CallbackTransformer.php';

Etl::service('db')->addConnection([
  'driver' => 'mysql',
  //'host' => 'openlist-db',
  //'port' => '3306',
  'host' => '127.0.0.1',
  'port' => '32768',
  'database' => 'db',
  'username' => 'db',
  'password' => 'db',
], 'openlist');

Etl::service('db')->addConnection([
  'driver' => 'mysql',
  //'host' => 'materiallist-db',
  //'port' => '3306',
  'host' => '127.0.0.1',
  'port' => '32769',
  'database' => 'db',
  'username' => 'db',
  'password' => 'db',
], 'materiallist');

$container = Container::getInstance();
$container->bind('callback_transformer', CallbackTransformer::class);

/* @var \Marquine\Etl\Row $last_row */
$last_row = null;

try {
  $etl = new Etl();
  $etl
    ->extract('query', <<<SQL
SELECT l.owner, e.data, e.created
FROM elements AS e
JOIN lists AS l ON e.list_id = l.list_id
WHERE
l.type IN ("user_list", "remember")
AND e.library_code REGEXP '[0-9]{6}'
SQL
      , ['connection' => 'openlist'])
    ->transform('callback', [
      'callback' => function (Row $row) {
        $owner = $row->get('owner');
        $row->set('owner', 'legacy-' . $owner);
      }
    ])
    ->transform('callback', [
      'callback' => function (Row $row) {
        $row->set('list', 'default');
      }
    ])
    ->transform('callback', [
      'callback' => function (Row $row) {
        $data = unserialize($row->get('data'));

        if (empty($data['value'])) {
          var_dump($row->toArray());
          $row->discard();
        } else {
          $row->set('data', $data['value']);
        }
      }
    ])
    ->transform('unique_rows', ['columns' => ['owner', 'list', 'data']])
    ->transform('callback', [
      'callback' => function (Row $row) {
        global $last_row;
        $last_row = $row;
      }
    ])
    ->load('insert', 'materials', [
      'columns' => [
        'owner' => 'guid',
        'list' => 'list',
        'data' => 'material',
        'created' => 'changed_at'
      ],
      'connection' => 'materiallist'
    ])
    ->run();
} catch (Exception $e) {
  var_dump($last_row->toArray());
  throw $e;
}
