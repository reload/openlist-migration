<?php

use Marquine\Etl\Loaders\Insert;

class InsertFileLoader extends Insert
{
  private $file;

  protected $filename;

  protected $availableOptions = [
    'columns', 'connection', 'timestamps', 'transaction', 'commitSize', 'filename'
  ];

  public function initialize()
  {
    parent::initialize();

    // Ensure that we have a valid path.
    $dir = dirname($this->filename);
    file_exists($dir) || mkdir($dir);

    $this->file = gzopen("{$this->filename}.gz", 'w');
  }

  public function insert($row)
  {
    // This part is basicly lifted from the parent function.
    if (! $this->insert) {
      $this->prepareInsert($row);
    }

    if ($this->columns) {
      $result = [];

      foreach ($this->columns as $key => $column) {
        isset($row[$key]) ? $result[$column] = $row[$key] : $result[$column] = null;
      }

      $row = $result;
    }

    if ($this->timestamps) {
      $row['created_at'] = $this->time;
      $row['updated_at'] = $this->time;
    }

    // Instead of executing the prepared query we replace our value into the
    // generated string and write it to a file.
    $query = $this->interpolateQuery($this->insert->queryString, $row);
    gzwrite($this->file, "{$query};\n");
  }

  public function finalize()
  {
    parent::finalize();
    gzclose($this->file);
  }

  /**
   * Replaces any parameter placeholders in a query with the value of that
   * parameter. Useful for debugging. Assumes anonymous parameters from
   * $params are are in the same order as specified in $query.
   *
   * @param string $query The sql query with parameter placeholders
   * @param array $params The array of substitution parameters
   * @return string The interpolated query
   *
   * @see https://stackoverflow.com/a/53966487
   */
  protected function interpolateQuery($query, $params) {
    $keys = array();
    $values = $params;

    # build a regular expression for each parameter
    foreach ($params as $key => $value) {
      if (is_string($key)) {
        $keys[] = '/:'.$key.'/';
      } else {
        $keys[] = '/[?]/';
      }

      if (is_array($value))
        $values[$key] = implode(',', $value);

      if (is_null($value))
        $values[$key] = 'NULL';
    }
    // Walk the array to see if we can add single-quotes to strings
    array_walk($values, function(&$v, $k) { if (!is_numeric($v) && $v != "NULL") $v = "'" . $this->db->pdo($this->connection)->quote($v) . "'"; });

    $query = preg_replace($keys, $values, $query, 1, $count);

    return $query;
  }

}
