<?php

use Marquine\Etl\Row;
use Marquine\Etl\Transformers\Transformer;

class CallbackTransformer extends Transformer
{

  /** @var callable */
  protected $callback;

  protected $availableOptions = [
    'callback'
  ];

  /**
   * Transform the given row.
   *
   * @param \Marquine\Etl\Row $row
   *
   * @return void
   */
  public function transform(Row $row)
  {
    call_user_func($this->callback, $row);
  }
}
