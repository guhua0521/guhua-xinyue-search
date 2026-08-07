<?php


use think\facade\Route;


Route::get('s/<name>-<page?>-<cate?>', 'index/index/list')->pattern(['name' => '[^-]+', 'id' => '\d+', 'cate' => '\d+']);
Route::get('s/<name>.html', 'index/index/list')->pattern(['name' => '[^.]+']);
Route::get('d/:id','index/index/detail');
Route::get('show','index/index/show');
Route::get('category','index/index/category');
Route::get('category/:id','index/index/category');
Route::get('sitemap.xml', 'index/sitemap/index');
Route::get('ranking', 'index/ranking');
Route::get('latest', 'index/latest');


 