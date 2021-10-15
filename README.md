# php-promise-swoole

PHP's Promse implementation depends on the Swoole module.

```php
Promise::allsettled([
        /** Timer 调用 */
        /** Timer call */
        new Promise(function($resolve) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(1); }); }),

        /** 函数式调用, 协程 sleep 的等待 */
        /** Functional call, waiting for the coroutine sleep */
        Promise(function($resolve, $reject) { Co::sleep(0.01); $reject(2); }),

        /** 使用带参函数时, 参数名必须是 `$resolve` */
        /** When using a function with parameters, the parameter name must be `$resolve` */
        function($resolve) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(3); }); },
    ],
    /** 无参的函数, 将以结果来判断是调用 resolve 或者 reject */
    /** For functions without parameters, the result will be used to determine whether to call resolve or reject */
    function() { return 4; },

    /** 同上, 直接根据内容判断 */
    /** Same as above, judge directly based on the content */
    '5', false,

    /** 静态调用 */
    /** Static call */
    Promise::reject(6),
    Promise::resolve(7),

    /** The method of using Timer directly, this method will only call resolve */
    Swoole\Timer::after(200, function() { var_dump('8'); }),

    /** 如果是使用了 tick 则会在第一次调用完成时, 自动清理该定时器, 这样做的原因是为了避免内存泄露啊. */
    /** If tick is used, the timer will be automatically cleaned up when the first call is completed. The reason for this is to avoid memory leaks. */
    Swoole\Timer::tick(200, function() { var_dump('9'); })
)->then(function($response){
    var_dump($response);
});

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

Promise::race([
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(1); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($reject){ $reject(2); }); }),
    new Promise(function($resolve, $reject) { Swoole\Timer::after(100, function() use ($resolve){ $resolve(3); }); }),
])->then(function($response){
    var_dump($response);
});

Promise::pipe([
        function($resolve, $reject, $response) {
            var_dump("pipe 1: ". $response); Co::sleep(1); return $resolve(1);
        },

        function($resolve, $reject, $response) {
            var_dump("pipe 2: ". $response); Co::sleep(1); return $resolve(2);
        },
    ],
        function($response) {
            var_dump("pipe 3: ". $response); Co::sleep(1); return MyPROMISE::resolve(3);
        }
    )->then(function(){
        var_dump('over', func_get_args());
    });
```

