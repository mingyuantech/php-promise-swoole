# php-promise-swoole

PHP's Promse implementation depends on the Swoole module.

```php
// basic example
$promise = new Promise(function(callable $resolve, callable $reject){
        Co::sleep(1); $resolve();
    });

$promise->then(function(){var_dump('then');})
    ->catch(function(){var_dump('catch');})
    ->finally(function(){var_dump('finally');});

// static example
Promise::reject()->catch(function(){var_dump('static reject catch');});
Promise::resolve()->then(function(){var_dump('static resolve catch');});

// chain example
Promise::resolve(111)->then(new Promise(function($resolve, $reject) {
    Co::sleep(0.1); $resolve(222);
}))->then(function($response) { var_dump($response); });

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

// defer example
(function(){
    $defer = Promise::defer();
    Go(function()use($defer){ Co::sleep(1); $defer->resolve(1); });
    return $defer->promise;
})()->then(function(){ var_dump('success'); });

// array promise example
Promise::all([
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(1); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(2); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(3); }); }),
])->then(function($response){
    var_dump($response);
});

Promise::all([
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(1); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($reject){ $reject(2); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(3); }); }),
])->then(function($response){
    var_dump($response);
});

Promise::allsettled([
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(1); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(10, function() use ($resolve){ $resolve(2); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(3); }); }),
])->then(function($response){
    var_dump($response);
});

Promise::allsettled([
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(1); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($reject){ $reject(2); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(3); }); }),
])->then(function($response){
    var_dump($response);
});

```

