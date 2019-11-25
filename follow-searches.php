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
  //'host' => '127.0.0.1',
  //'port' => '32768',
  //'database' => 'db',
  //'username' => 'db',
  //'password' => 'db',
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
  //'host' => 'followsearches-db',
  //'port' => '3306',
  //'host' => '127.0.0.1',
  //'port' => '32771',
  //'database' => 'db',
  //'username' => 'db',
  //'password' => 'db',
  'host' => 'localhost',
  'port' => '3306',
  'database' => 'followsearches',
  'username' => 'root',
  'password' => 'root',
  'charset' => 'utf8mb4',
  'collation' => 'utf8mb4_unicode_ci',
], 'followsearches');

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

function migrate(string $library_code) {
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
l.type IN ("follow_author", "user_searches")
AND e.data NOT LIKE '%ting_object%'
AND e.library_code = :library_code
SQL
        , [
            'bindings' => [ 'library_code' => $library_code ],
            'connection' => 'openlist',
          ]
      )
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
          if ($data['type'] &&
            !in_array($data['type'], ['search_query', 'follow_author'])) {
            var_dump($row->toArray());
            $row->discard();
          }

          if (empty($data['value'])) {
            var_dump($row->toArray());
            $row->discard();
          } else {
            $row->set('data', $data['value']);
          }
        }
      ])
      ->transform('unique_rows', ['columns' => ['owner', 'list', 'hash']])
      ->transform('callback', [
        'callback' => function (Row $row) {
          $query = $row->get('data');
          $title = $query;
          if (preg_match('/^(.+?)\?/u', $query, $matches)) {
            $title = $matches[1];
          } elseif (preg_match('/^phrase\.creator=\"(.+)\"/u', $query, $matches)) {
            $title = $matches[1];
          }

          // Truncate excessively long titles.
          $title = mb_substr($title, 0, 254);
          $row->set('title', $title);
        }
      ])
      ->transform('callback', [
          'callback' => function(Row $row) {
            $query = $row->get('data');
            if (preg_match('/^(.*?)\?(.*)$/u', $query, $matches)) {
              parse_str($matches[2], $args);
              if (!empty($args['facets'])) {
                $facet_queries = array_filter(array_map(function (string $facet) {
                  if (preg_match('/^(.+?)\:(.+)/u', $facet, $matches)) {
                    return sprintf('%s="%s"', $matches[1],$matches[2]);
                  } else {
                    return FALSE;
                  }
                }, $args['facets']));
                $query = $matches[1] . ' and ' . implode(' and ', $facet_queries);
                $row->set('data', $query);
              }
            }
          }
        ]
      )
      ->transform('callback', [
        'callback' => function (Row $row) {
          $query = $row->get('data');
          $row->set('hash', hash('sha512', $query));
        }
      ])
      // Register the row for debugging. If an database error occurs it can be
      // hard to tell what was going to be inserted. Here it is nice to have
      // the actual row available.
      ->transform('callback', [
        'callback' => function (Row $row) use (&$last_row) {
          $last_row = $row;
        }
      ])
      ->load('insert_file', 'searches', [
        'columns' => [
          'owner' => 'guid',
          'list' => 'list',
          'title' => 'title',
          'data' => 'query',
          'hash' => 'hash',
          'created' => 'changed_at'
        ],
        'connection' => 'followsearches',
        'filename' => "./results/followsearches-{$library_code}.sql"
      ])
      ->run();
  } catch (Exception $e) {
    if (!empty($last_row)) {
      var_dump($last_row->toArray());
    }
    throw $e;
  }
}
