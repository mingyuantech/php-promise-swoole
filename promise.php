<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
**************************************************/

namespace promise {
    use Co;

    use ReflectionClass;

    define('promise_states', ['pending', 'canceled', 'rejected', 'fulfilled']);

    define('promise_fail_states', array_slice(promise_states, 1, 2));

    class proto {
        const pending = promise_states[0];

        const canceled = promise_states[1];

        const rejected = promise_states[2];

        const fulfilled = promise_states[3];

        private $id;

        private $state = promise_states[0];

        private $result;

        private $suspend = false;

        private $rejects = [];

        private $resolves = [];

        function __construct(callable $fn = null) {
            $fn && Go([$this, '_exec'], $fn);
        }

        function __get(string $property) {
            switch ($property) {
                case 'state': return $this->state;

                case 'result': return $this->result;
            }
        }

        public function wait() {
            if ( $this->state === self::pending ) {
                if ( !$this->id ) { $this->id = Co::getCid(); }

                $this->suspend = true; Co::suspend();

                $this->_canceled();
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

        private function _exec(callable $fn) {
            $rc = new ReflectionClass($this);

            $reject = $rc->getMethod('_reject');

            $resolve = $rc->getMethod('_resolve');

            $fn($resolve->getClosure($this), $reject->getClosure($this));

            $this->_canceled();
        }

        private function _result(string $state, $result = null) {
            if ( $this->state !== self::pending ) { return ; }

            $this->state = $state;

            $this->result = $result;

            $this->_handler();
        }

        private function _reject($result = null) {
            $this->_result(self::rejected, $result);
        }

        private function _resolve($result = null) {
            $this->_result(self::fulfilled, $result);
        }

        private function _handler() {
            $handlers = &$this->{$this->state === self::fulfilled ? 'resolves' : 'rejects'};

            while ($handler = array_shift($handlers)) { $handler($this->result); }

            array_splice($this->rejects, 0);

            array_splice($this->resolves, 0);

            $this->id && $this->suspend && Co::exists($this->id) && Co::resume($this->id);

            $this->suspend = false;

            return $this;
        }

        private function _canceled() {
            if ( $this->state === self::pending && Co::isCanceled() ) {
                $this->state = $this->result = self::canceled;
            }
        }
    }

    /**
     * 解忧器
     * @readme 更自由的调用 promise
     */
    class resolvers {
        private $promise;

        private $reflection;

        function __construct() {
            $this->promise = new proto();

            $this->reflection = new ReflectionClass($this->promise);
        }

        function __get(string $property) {
            return $property === 'promise' ? $this->promise : null;
        }

        public function wait() {
            return $this->promise->wait();
        }

        public function reject($result = null) {
            return $this->reflection->getMethod('_reject')->getClosure($this->promise)($result);
        }

        public function resolve($result = null) {
            return $this->reflection->getMethod('_resolve')->getClosure($this->promise)($result);
        }
    }

    /**
     * 限制器
     * @readme 限制 promise 的并发数量
     */
    class restrictor {
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
}

namespace {
    use promise\{proto, resolvers, restrictor};

    class promise extends proto  {
        static public function try(callable $fn, ...$args) {
            return new self(function($resolve, $reject) use ($fn, $args) {
                try {
                    if ( !(($result = $fn(...$args)) instanceof self) ) { return $resolve($result); }

                    $result->wait(); return $result->state === proto::fulfilled ? $resolve($result->result) : $reject($result->result);
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
                        case proto::canceled:
                        case proto::rejected:
                            return $reject($it->result);

                        case proto::fulfilled:
                            $results[$i] = $it->result; --$sizeof; break;

                        default:
                            $it->finally(function($result = null) use (&$finallyed, &$results, &$sizeof, $settled, $resolve, $reject, $it, $i) {
                                if ( $finallyed ) { return ; }

                                if ( !$settled && $it->state === proto::rejected ) {
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
                    if ( $it->state !== proto::pending ) {
                        return in_array($it->state, promise_fail_states, true) ? $reject($it->result) : $resolve($it->result);
                    }

                    $it->finally(function($result = null) use (&$finallyed, $promises, $resolve, $reject, $it) {
                        if ( $finallyed ?: !($finallyed = true) ) { return ; }

                        in_array($it->state, promise_fail_states, true) ? $reject($result) : $resolve($result);
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

    function is_promise($any = null): bool {
        return is_object($any) && $any instanceof helper\promise\proto;
    }

    function is_resolvers($any = null): bool {
        return is_object($any) && $any instanceof helper\promise\resolvers;
    }

    function is_restrictor($any = null): bool {
        return is_object($any) && $any instanceof helper\promise\restrictor;
    }
}
