<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types = 1);

namespace Cawa\Router;

use Behat\Transliterator\Transliterator;
use Cawa\App\HttpFactory;
use Cawa\Cache\CacheFactory;
use Cawa\Controller\AbstractController;
use Cawa\Events\DispatcherFactory;
use Cawa\Events\TimerEvent;
use Cawa\Intl\TranslatorFactory;
use Cawa\Log\LoggerFactory;
use Cawa\Net\Uri;
use Cawa\Session\SessionFactory;

class Router
{
    use LoggerFactory;
    use DispatcherFactory;
    use TranslatorFactory;
    use CacheFactory;
    use SessionFactory;
    use HttpFactory;

    const OPTIONS_SESSION = 'SESSION';
    const OPTIONS_CACHE = 'CACHE';
    const OPTIONS_MASTERPAGE = 'MASTERPAGE';
    const OPTIONS_CONDITIONS = 'CONDITIONS';

    /**
     * @var Route[]
     */
    private $routes = [];

    /**
     * @var Route
     */
    private $currentRoute;

    /**
     * @return string
     */
    public function current() : Route
    {
        return $this->currentRoute;
    }

    /**
     * @var Route[]
     */
    private $errors = [];

    /**
     * @param array $routes
     *
     * @throws \InvalidArgumentException
     */
    public function addRoutes(array $routes)
    {
        /** @var Route[] $routesTranform */
        $routesTranform = [];

        foreach ($routes as $name => $route) {
            if (is_string($route)) {
                $routesTranform[$name] = Route::create($route);
            } elseif (is_array($route)) {
                $routesTranform = array_merge($routesTranform, $this->addRecursiveRoute([$name], $route));
            } else {
                $routesTranform[$name] = $route;
            }
        }

        foreach ($routesTranform as $name => $route) {
            if (!$route->getName() && !$route->getResponseCode()) {
                $route->setName($name);
            }

            if (!$route instanceof Route) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid route, got %s',
                    is_object($route) ? get_class($route) : gettype($route)
                ));
            }

            if (!$route->getName() && !$route->getResponseCode()) {
                throw new \InvalidArgumentException('Missing route name');
            }

            if (isset($this->routes[$route->getName()])) {
                throw new \InvalidArgumentException(sprintf("Duplicate route name '%s'", $route->getName()));
            }

            if ($route->getResponseCode()) {
                $this->errors[$route->getResponseCode()] = $route;
            } else {
                $this->routes[$route->getName()] = $route;
            }
        }
    }

    /**
     * @param array $path
     * @param array|Route $routes
     *
     * @return Route[]
     */
    private function addRecursiveRoute(array $path, $routes) : array
    {
        $return = [];
        foreach ($routes as $currentPath => $route) {
            if (is_string($route) || $route instanceof Route) {
                if (is_string($route)) {
                    $route = Route::create($route)
                        ->setName($currentPath);
                } else {
                    $route->setName($currentPath);
                }

                $match = $path;
                if ($route->getMatch()) {
                    $match[] = $route->getMatch();
                }

                $route->setMatch(implode('/', $match));

                $return[$route->getName()] = $route;
            } else {
                $return = array_merge(
                    $return,
                    $this->addRecursiveRoute(array_merge($path, [$currentPath]), $route)
                );
            }
        }

        return $return;
    }

    /**
     * @var Route[]
     */
    private $uris = [];

    /**
     * @param array $uris
     */
    public function addUris(array $uris)
    {
        foreach ($uris as $key => &$uri) {
            foreach ($uri as $locale => &$value) {
                $value = Transliterator::urlize($value);
            }
        }

        $this->uris = array_merge_recursive($this->uris, $uris);
    }

    /**
     * @param string $name
     * @param array $data
     * @param bool $absolute
     *
     * @return string
     */
    public function getUri(string $name, array $data = [], bool $absolute = false) : string
    {
        if (!isset($this->routes[$name])) {
            throw new \InvalidArgumentException(sprintf("Invalid route name '%s'", $name));
        }

        $route = $this->routes[$name];
        $return = $this->routeRegexp($route, $data);

        // append querystring
        if ($route->getUserInput()) {
            $queryToAdd = [];
            foreach ($route->getUserInput() as $querystring) {
                if (!isset($data[$querystring->getName()]) && $querystring->isMandatory()) {
                    throw new \InvalidArgumentException(sprintf(
                        "Missing querystring '%s' to generate route '%s'",
                        $querystring->getName(),
                        $route->getName()
                    ));
                }

                $queryToAdd[$querystring->getName()] = $data[$querystring->getName()];
            }

            $uri = new Uri();
            $uri->setPath($return);
            $uri->addQueries($queryToAdd);
            $return = $uri->get();
        }

        if ($absolute) {
            $uri = new Uri();
            $uri
                ->removeAllQueries()
                ->setFragment(null)
                ->setPath($return);
            $return  = $uri->get(false);
        }

        return $return;
    }

    /**
     * @param string $url
     * @param Route $route
     *
     * @return array
     */
    private function match(string $url, Route $route) : array
    {
        $regexp = $this->routeRegexp($route);

        if (preg_match_all('`^' . $regexp . '$`', $url, $matches, PREG_SET_ORDER)) {
            // remove all numeric matches
            $matches = array_diff_key($matches[0], range(0, count($matches[0])));

            // control query string
            if ($route->getUserInput()) {
                foreach ($route->getUserInput() as $querystring) {
                    $method = $this->request()->getMethod() == 'POST' ? 'getPostOrQuery' : 'getQuery';
                    $value = $this->request()->$method($querystring->getName(), $querystring->getType());

                    if (is_null($value) && $querystring->isMandatory()) {
                        return [false, null, $regexp];
                    }

                    $matches[$querystring->getName()] = $value;
                }
            }

            return [true, $matches, $regexp];
        }

        return [false, null, $regexp];
    }

    /**
     * @param Route $route
     * @param array $data
     *
     * @return string
     */
    private function routeRegexp(Route $route, array $data = null) : string
    {
        $regexp = $route->getMatch();

        // AbstractApp::logger()->debug($regexp);

        preg_match_all('`\{\{(.+)\}\}`U', $regexp, $matches);
        if (sizeof($matches[1]) == 0) {
            return $regexp;
        }

        foreach ($matches[1] as $var) {
            $replace = '{{' . $var . '}}';
            $explode = explode(':', $var);
            $type = array_shift($explode);
            $value = implode(':', $explode) ;
            unset($dest);

            switch ($type) {
                case 'T':
                    if (!$value) {
                        throw new \InvalidArgumentException(
                            sprintf("Missing router var on route '%s'", $route->getName())
                        );
                    }

                    if (!isset($this->uris[$value])) {
                        throw new \InvalidArgumentException(
                            sprintf("Missing translations for var '%s' on route '%s'", $value, $route->getName())
                        );
                    }

                    if (!is_null($data)) {
                        $dest = $this->uris[$value][self::translator()->getLocale()];
                    } else {
                        $dest = '(?:' . implode('|', $this->uris[$value]) . ')';
                    }
                    break;

                case 'L':
                    if (!is_null($data)) {
                        $dest = self::translator()->getLocale();
                    } else {
                        $dest = '(?:' . implode('|', self::translator()->getLocales()) . ')';
                    }
                    break;

                case 'C':
                case 'O':
                    $variable = substr($value, 1, strpos($value, '>') - 1);
                    $capture = substr($value, strpos($value, '>') + 1);
                    if (empty($capture)) {
                        $capture = '[^/]+';
                    }

                    if (!is_null($data)) {
                        if (!array_key_exists($variable, $data) && $type == 'C') {
                            throw new \InvalidArgumentException(sprintf(
                                "Missing variable route '%s' to generate route '%s'",
                                $variable,
                                $route->getName()
                            ));
                        }

                        if (isset($data[$variable])) {
                            if ($route->getOption(Route::OPTIONS_URLIZE) === false) {
                                $dest = $data[$variable];
                            } else {
                                $dest = Transliterator::urlize($data[$variable]);
                            }
                        }
                    } else {
                        $dest = '(?<' . $variable . '>' . $capture . ')';
                        if ($type == 'O') {
                            $dest .= '?';
                        }
                    }

                    break;

                default:
                    throw new \InvalidArgumentException(
                        sprintf("Invalid route variable '%s' on route '%s'", $type, $route->getName())
                    );
            }

            $regexp = str_replace(
                $replace,
                isset($dest) ? $dest : '',
                $regexp
            );
        }

        return $regexp;
    }

    /**
     * @return string|array|\SimpleXMLElement|null
     */
    public function handle()
    {
        $uri = clone $this->request()->getUri();
        $uri->removeAllQueries();
        $url = $uri->get();

        $event = new TimerEvent('router.match');

        $count = 0;
        foreach ($this->routes as $route) {

            list($result, $args, $regexp) = $this->match($url, $route);

            if ($route->getMethod() && $route->getMethod() != $this->request()->getMethod()) {
                $result = false;
            }

            $count++;

            if ($result == false) {
                continue;
            }

            $event->setData([
                'analyseRoute' => $count,
                'name' => $route->getName(),
                'regexp' => $regexp,
                'controller' => is_string($route->getController()) ? $route->getController() : 'Callable',
                'args' => $args
            ]);

            self::dispatcher()->emit($event);

            $cacheKey = $this->cacheKey($route, $args);

            if ($return = $this->cacheGet($route, $cacheKey)) {
                return $return;
            }

            $return = $this->callController($route, $args);

            $this->cacheSet($route, $cacheKey, $return);

            return $return;
        }

        return $this->return404();
    }

    /**
     * @return mixed
     */
    private function return404()
    {
        if (isset($this->errors[404])) {
            $this->response()->setStatus(404);

            return $this->callController($this->errors[404], []);
        } else {
            return '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">' . "\n" .
                 '<html><head>' . "\n" .
                 '<title>404 Not Found</title>' . "\n" .
                 '</head><body>' . "\n" .
                 '<h1>Not Found</h1>' . "\n" .
                 '</body></html>';
        }
    }

    /**
     * @param Route $route
     * @param array $args
     *
     * @return string
     */
    private function cacheKey(Route $route, array $args) : string
    {
        switch (gettype($route->getController())) {
            case 'string':
                $controller = $route->getController();
                break;

            case 'array':
                $controller = implode('::', $route->getController());
                break;

            default:
                if (is_callable($route->getController())) {
                    $controller = 'callable_' . spl_object_hash($route->getController());
                } else {
                    throw new \LogicException('Unexpected type');
                }
        }

        return $controller . '(' . json_encode($args) . ')';
    }

    /**
     * @param Route $route
     * @param string $cacheKey
     *
     * @return string|bool
     */
    private function cacheGet(Route $route, string $cacheKey)
    {
        if ($route->getOption(Route::OPTIONS_CACHE)) {
            if ($data = self::cache('OUTPUT')->get($cacheKey)) {
                foreach ($data['headers'] as $name => $header) {
                    $this->response()->addHeader($name, $header);
                }

                return $data['output'];
            } else {
                return false;
            }
        }

        return false;
    }

    /**
     * @param Route $route
     * @param string $cacheKey
     * @param $return
     *
     * @return bool
     */
    private function cacheSet(Route $route, string $cacheKey, $return) : bool
    {
        if ($route->getOption(Route::OPTIONS_CACHE)) {
            $second = $route->getOption(Route::OPTIONS_CACHE);

            if (self::session()->isStarted()) {
                throw new \LogicException("Can't set a cache on a route that use session data");
            }

            $this->response()->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + $second) . ' GMT');
            $this->response()->addHeader('Cache-Control', 'public, max-age=' . $second . ', must-revalidate');
            $this->response()->addHeader('Pragma', 'public, max-age=' . $second . ', must-revalidate');
            $this->response()->addHeader('Vary', 'Accept-Encoding');

            $data = [
                'output' => $return,
                'headers' => $this->response()->getHeaders()
            ];

            self::cache('OUTPUT')->set($cacheKey, $data, $second);
        }

        return true;
    }

    /**
     * @param string $vars
     * @param Route $route
     * @param array $args
     *
     * @return string
     */
    private function replaceDynamicArgs(string $vars, Route $route, array $args) : string
    {
        return preg_replace_callback('`<([A-Za-z_0-9]+)>`', function ($match) use ($route, $args) {
            if (!isset($args[$match[1]])) {
                throw new \InvalidArgumentException(sprintf(
                    "Invalid route name '%s', missing dynamics controler param '%s'",
                    $route->getName(),
                    $match[1]
                ));
            }

            return str_replace('/', '\\', $args[$match[1]]);
        }, $vars);
    }

    /**
     * @param Route $route
     * @param array $args
     *
     * @return string|array|\SimpleXMLElement
     */
    private function callController(Route $route, array $args)
    {
        $callback = $route->getController();

        $this->currentRoute = $route;

        // simple function
        if (is_callable($callback) && is_object($callback)) {
            return call_user_func_array($callback, [$args]);
        }

        // controller
        preg_match('`([A-Za-z_0-9\\\\<>]+)(?:::)?([A-Za-z_0-9_<>]+)?`', $callback, $find);

        $class = $this->replaceDynamicArgs($find[1], $route, $args);
        $method = isset($find[2]) ? $this->replaceDynamicArgs($find[2], $route, $args) : null;

        // replace class dynamic args
        if (!class_exists($class)) {
            throw new \BadMethodCallException(sprintf(
                "Can't load class '%s' on route '%s'",
                $class,
                $route->getName()
            ));
        }

        $controller = new $class();

        if (is_null($method)) {
            $method = 'get';

            if ($this->request()->isAjax() && method_exists($controller, 'ajax')) {
                $method = 'ajax';
            } elseif ($this->request()->getMethod() == 'POST' && method_exists($controller, 'post')) {
                $method = 'post';
            }
        }

        $ordererArgs = $this->mapArguments($controller, $method, $args);

        $event = new TimerEvent('router.mainController');
        $event->setData([
            'controller' => $class,
            'method' => $method,
            'data' => $ordererArgs,
        ]);

        if (method_exists($controller, 'init')) {
            call_user_func_array([$controller, 'init'], $ordererArgs);
        }

        $return = call_user_func_array([$controller, $method], $ordererArgs);

        self::dispatcher()->emit($event);

        return $return;
    }

    /**
     * @param AbstractController $controller
     * @param string $method
     * @param array $args
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    private function mapArguments(AbstractController $controller, string $method, array $args) : array
    {
        $return = [];

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod($method);

        foreach ($method->getParameters() as $parameter) {
            if (!isset($args[$parameter->getName()]) && $parameter->isOptional() === false) {
                throw new \InvalidArgumentException(
                    sprintf("Missing mandatory arguments '%s' on '%s'", $parameter->getName(), get_class($controller))
                );
            }

            if (isset($args[$parameter->getName()]) && $args[$parameter->getName()] != '') {
                $value = $args[$parameter->getName()];

                if ($parameter->getClass() && $parameter->getClass()->getName() == 'Cawa\Date\DateTime') {
                    $class = $parameter->getClass()->getName();
                    $value = new $class($value);
                }

                $return[$parameter->getName()] = $value;
            } elseif (!isset($args[$parameter->getName()])) {
                $return[$parameter->getName()] = null;
            }
        }

        return $return;
    }
}
