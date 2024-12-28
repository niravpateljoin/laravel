<?php

namespace App\Helpers;

class Constant {

    public function __construct()
    {
        config([
            'global.DATETIME_FORMAT' => Constant::DATETIME_FORMAT,
            'global.DATE_FORMAT' => Constant::DATE_FORMAT
        ]);
    }

   const DATETIME_FORMAT = 'd M, Y - h:m A';
   const DATE_FORMAT = 'd M, Y';

}
