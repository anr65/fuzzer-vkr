<?php


global $input;

use Illuminate\Http\Request;


$req = Request::create(
    "/?q=" . $input,
    "GET"
);