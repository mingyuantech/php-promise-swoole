<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
**************************************************/

final class defer {
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
            $this->id = Co::getCid();

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

    static public function defer() { return new defer(); }

    static public function reject($result = null) { return (new defer())->reject($result); }

    static public function resolve($result = null) { return (new defer())->resolve($result); }

    static public function allsettled(array $promises) { return self::all($promises, true); }

    static public function withResolvers() { return self::defer(); }

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
}
