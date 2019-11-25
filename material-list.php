<?php

use Marquine\Etl\Container;
use Marquine\Etl\Etl;
use Marquine\Etl\Row;

require 'vendor/autoload.php';
require 'CallbackTransformer.php';
require 'InsertFileLoader.php';

Etl::service('db')->addConnection([
  'driver' => 'mysql',
  //'host' => 'openlist-db',
  //'port' => '3306',
  'host' => 'localhost',
  'port' => '3306',
  'database' => 'openlist',
  'username' => 'root',
  'password' => 'root',
  'charset' => 'utf8',
  'collation' => 'utf8_general_ci',
], 'openlist');

Etl::service('db')->addConnection([
  'driver' => 'mysql',
  //'host' => 'materiallist-db',
  //'port' => '3306',
  'host' => 'localhost',
  'port' => '3306',
  'database' => 'materiallist',
  'username' => 'root',
  'password' => 'root',
  'charset' => 'utf8mb4',
  'collation' => 'utf8mb4_unicode_ci',
], 'materiallist');

$container = Container::getInstance();
$container->bind('callback_transformer', CallbackTransformer::class);
$container->bind('insert_file_loader', InsertFileLoader::class);

$pdo = new PDO('mysql:host=localhost;port=3307;dbname=openlist', 'root', 'root');
$result = $pdo->query(<<< SQL
SELECT DISTINCT e.library_code
FROM elements AS e
WHERE e.library_code REGEXP '[0-9]{6}'
SQL);
while ($library_code = $result->fetchColumn()) {
  $library_codes[] = $library_code;
}

array_walk($library_codes, 'migrate');

function migrate($library_code)
{
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
AND e.library_code = :library_code
SQL
        ,
        [
            'bindings' => [ 'library_code' => $library_code ],
            'connection' => 'openlist'
        ])
      ->transform('callback', [
        'callback' => function (Row $row) {
          // Prefix owner names with legacy-. This is required by materiallist
          // as a way to identify users waiting to be migrated.
          $owner = $row->get('owner');
          $row->set('owner', 'legacy-' . $owner);
        }
      ])
      ->transform('callback', [
        'callback' => function (Row $row) {
          // The default list name is "default"
          $row->set('list', 'default');
        }
      ])
      ->transform('callback', [
        'callback' => function (Row $row) {
          // Extract data consisting of serialized PHP from each element.
          $data = unserialize($row->get('data'));
          // We only care about elements referring to materials. Discard
          // other types which might have found their way in here.
          if (!empty($data['type'])
            && $data['type'] !== 'ting_object') {
            var_dump($row->toArray());
            $row->discard();
          }

          // Discard elements without a material id.
          if (empty($data['value'])) {
            var_dump($row->toArray());
            $row->discard();
          }

          $row->set('data', $data['value']);
        }
      ])
      // Only allow one of each material on the list. As we migrate from multiple
      // lists to one it is important that we do not include duplicates.
      ->transform('unique_rows', ['columns' => ['owner', 'list', 'data']])
      // Register the row for debugging. If an database error occurs it can be
      // hard to tell what was going to be inserted. Here it is nice to have
      // the actual row available.
      ->transform('callback', [
        'callback' => function (Row $row) use (&$last_row) {
          $last_row = $row;
        }
      ])
      ->load('insert_file', 'materials', [
        'columns' => [
          'owner' => 'guid',
          'list' => 'list',
          'data' => 'material',
          'created' => 'changed_at'
        ],
        'connection' => 'materiallist',
        'filename' => "./results/materiallist-{$library_code}.sql"
      ])
      ->run();
  } catch (Exception $e) {
    var_dump($last_row->toArray());
    throw $e;
  }
}
