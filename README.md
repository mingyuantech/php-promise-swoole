# php-promise-swoole

PHP's Promse implementation depends on the Swoole module.

```php
(new Promise(function(callable $resolve, callable $reject){
    Co::sleep(1); $resolve();
}))->then(function(){var_dump('then');})
->catch(function(){var_dump('catch');})
->finally(function(){var_dump('finally');});

Promise::reject()->catch(function(){var_dump('static reject catch');});
Promise::resolve()->then(function(){var_dump('static resolve catch');});

Promise::resolve(111)->then(new Promise(function($resolve, $reject) {
    Co::sleep(0.1); $resolve(222);
}))->then(function($response) { var_dump($response); });

Promise::resolve(1)->then(function($response){ var_dump('DD:'.$response); });

(new Promise(function(callable $resolve, callable $reject){
    Co::sleep(1); var_dump('promise 1 over'); $resolve(1);
}))->then(function(){
    var_dump('promise 2 enter');
    return new Promise(function(callable $resolve, callable $reject){
        Co::sleep(1); var_dump('promise 2 over'); $resolve(2);
    });
})->finally(function(){var_dump('promise finally');})->then(function($response){
    var_dump('promise 3 enter val is: '.$response);
});

(function(){
    $defer = Promise::defer();
    Go(function()use($defer){ Co::sleep(1); $defer->resolve(1); });
    return $defer->promise;
})()->then(function(){ var_dump('success'); });

```
