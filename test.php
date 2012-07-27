<?php
$csv	= file('points.csv');

$data	= array_map('str_getcsv', $csv);

// GUI debug

var_dump($data);