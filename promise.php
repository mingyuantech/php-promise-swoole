<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
**************************************************/

final class promise {
    private $id;

    private $state = 'pending';

    private $result;

    private $suspend = false;

    private $rejects = [];

    private $resolves = [];

    function __construct(callable $fn = null) {
        $reject = new ReflectionMethod($this, '_reject');

        $resolve = new ReflectionMethod($this, '_resolve');

        $fn && ($this->id = Go($fn, $resolve->getClosure($this), $reject->getClosure($this)));
    }

    function __get(string $property) {
        switch ($property) {
            case 'state': return $this->state;

            case 'result': return $this->result;
        }
    }

    public function wait() {
        if ( $this->state === 'pending' ) {
            if ( !$this->id ) {
                $this->id = Co::getCid();
            }

            $this->suspend = true;

            Co::suspend();
        }

        return $this->result;
    }

    public function then(callable $handler) {
        $this->resolves[] = $handler; return $this;
    }

    public function catch(callable $handler) {
        $this->rejects[] = $handler; return $this;
    }

    public function cancel() {
        $this->id && Co::cancel($this->id);
    }

    public function finally(callable $handler) {
        $this->rejects[] = $handler;

        $this->resolves[] = $handler;

        return $this;
    }

    private function _result(string $state, $result = null) {
        $this->state = $state;

        $this->result = $result;

        return $this->_handler();
    }

    private function _reject($result = null) {
        return $this->_result('rejected', $result);
    }

    private function _resolve($result = null) {
        return $this->_result('fulfilled', $result);
    }

    private function _handler() {
        $handlers = &$this->{$this->state === 'fulfilled' ? 'resolves' : 'rejects'};

        while ($handler = array_shift($handlers)) { $handler($this->result); }

        array_splice($this->rejects, 0);

        array_splice($this->resolves, 0);

        $this->id && $this->suspend && Co::resume($this->id);

        return $this;
    }

    static public function try(callable $fn, ...$args) {
        return new self(function($resolve, $reject) use ($fn, $args) {
            try {
                if ( !(($result = $fn(...$args)) instanceof self) ) { return $resolve($result); }

                $result->wait(); return $result->state === 'fulfilled' ? $resolve($result->result) : $reject($result->result);
            } catch (Exception $e) {
                $reject($e);
            }
        });
    }

    static public function all(array $promises, bool $settled = false) {
        return new self(function($resolve, $reject) use (&$promises, $settled) {
            $sizeof = sizeof($promises);

            $results = array_fill(0, $sizeof, null);

            $finallyed = false;

            foreach ($promises as $i => $it) {
                switch($it->state) {
                    case 'rejected':
                        return $reject($it->result);

                    case 'finallyed':
                        $results[$i] = $it->result; --$sizeof; break;

                    default:
                        $it->finally(function($result = null) use (&$finallyed, &$results, &$sizeof, $settled, $resolve, $reject, $it, $i) {
                            if ( $finallyed ) { return ; }

                            if ( !$settled && $it->state === 'rejected' ) {
                                $finallyed = true; return $reject($result);
                            }

                            $results[$i] = $result;

                            if ( --$sizeof < 1 ) { $resolve($results); }
                        });
                }
            }
        });
    }

    static public function any(array $promises) { return self::race($promises); }

    static public function race(array $promises) {
        return new self(function($resolve, $reject) use (&$promises) {
            $finallyed = false;

            foreach ($promises as $it) {
                if ( $it->state !== 'pending' ) {
                    return $it->state === 'rejected' ? $reject($it->result) : $resolve($it->result);
                }

                $it->finally(function($result = null) use (&$finallyed, $promises, $resolve, $reject, $it) {
                    if ( $finallyed ?: !($finallyed = true) ) { return ; }

                    $it->state === 'rejected' ? $reject($result) : $resolve($result);
                });
            }
        });
    }

    static public function pipe(...$args) {
        if ( empty($args) ) { return ; }

        $result = !is_promise($args[0]) && !is_callable($args[0]) ? array_shift($args) : null;

        foreach ($args as $arg) {
            if ( is_promise($arg) ) { $arg->wait(); }

            else if ( is_callable($arg) ) {
                is_promise($result = $arg($result)) && ($result = $result->wait());
            }
        }
    }

    static public function reject($result = null) { return (new resolvers())->reject($result); }

    static public function resolve($result = null) { return (new resolvers())->resolve($result); }

    static public function resolvers() { return new resolvers(); }

    static public function restrictor(int $capacity, callable $make) { return new restrictor($capacity, $make); }

    static public function allsettled(array $promises) { return self::all($promises, true); }

    static public function withResolvers() { return self::resolvers(); }
}

/**
 * 解忧器
 * @readme 更自由的调用 promise
 */
final class resolvers {
    function __construct() {
        $this->promise = new promise();
    }

    public function wait() {
        return $this->promise->wait();
    }

    public function reject($result = null) {
        return (new ReflectionMethod($this->promise, '_reject'))->getClosure($this->promise)($result);
    }

    public function resolve($result = null) {
        return (new ReflectionMethod($this->promise, '_resolve'))->getClosure($this->promise)($result);
    }
}

/**
 * 限制器
 * @readme 限制 promise 的并发数量
 */
final class restrictor {
    private $id;

    private $make;

    private $current = 0;

    private $capacity = 0;

    private $resolvers;

    private $finallyed = false;

    function __construct(int $capacity, callable $make) {
        $this->id = Co::getCid();

        $this->make = $make;

        $this->capacity = $capacity;

        $this->resolvers = new resolvers();

        $this->dispatch();
    }

    public function add(): bool {
        $promise = call_user_func($this->make);

        if ( !($this->finallyed = !is_promise($promise)) ) {
            $this->current++; $promise->finally([$this, 'defer']);
        }

        return !$this->finallyed;
    }

    public function wait() {
        return $this->resolvers->wait();
    }

    public function defer() {
        $this->current--;

        !$this->finallyed ? Co::resume($this->id) : (
            $this->current < 1 && $this->resolvers->resolve()
        );
    }

    public function dispatch() {
        do {
            while ($this->current < $this->capacity && $this->add()) {}
        } while (!$this->finallyed && Co::suspend());
    }
}

function is_promise($any = null): bool {
    return is_object($any) && $any instanceof promise;
}

function is_resolvers($any = null): bool {
    return is_object($any) && $any instanceof resolvers;
}
