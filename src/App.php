<?php

namespace Ssel;

use DateTime;
use Ssel\Event\EventInterface;
use Ssel\Event\CallableTimerEvent;
use Ssel\Event\CallableStartEvent;
use GuzzleHttp\Psr7\Response;
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory;

class App
{
    /**
     * @var array
     */
    protected $settings = [
        'execLimit' => 60,
        'retryTime' => 1,
        'keepAliveInterval' => 30,
        'sendEventName' => true,
    ];

    /**
     * @var int
     */
    protected $eventId;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var string
     */
    protected $keepMessage = 'keep alive';

    /**
     * @var array
     */
    protected $timers = [];

    /**
     * @var array
     */
    protected $events = [];

    /**
     * Create new sse
     *
     * @param $settings
     * @param $headers
     */
    public function __construct($settings = [], $headers = [])
    {
        $this->settings = array_merge($this->settings, $settings);
        $this->eventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 1;
        $this->response = $this->createResponse($headers);
        $this->loop = Factory::create();
    }

    /**
     *
     * @param array $headers
     * @return Response
     */
    protected function createResponse(array $headers = [])
    {
        $response = new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache'
        ] + $headers);

        if (isset($_SERVER['SERVER_SOFTWARE']) && preg_match('/^nginx\//', $_SERVER['SERVER_SOFTWARE'])) {
            return $response->withHeader('X-Accel-Buffering', 'no');
        }

        return $response;
    }

    /**
     * Add timer event handler
     *
     * @param string   $name the event name
     * @param callable $callback
     * @param float    $interval
     * @param boolean  $periodic
     * @return void
     */
    public function addTimerEvent($name, callable $callback, $interval = 5, $periodic = true)
    {
        $this->events[$name] = new CallableTimerEvent($name, $interval, $callback);
    }

    /**
     * Add start event handler
     *
     * @param string   $name the event name
     * @param callable $callback
     * @return void
     */
    public function addStartEvent($name, callable $callback)
    {
        $this->events[$name] = new CallableStartEvent($name, $callback);
    }

    /**
     * Add start event handler
     *
     * @param string         $name the event name
     * @param EventInterface $event
     * @return void
     */
    public function addEvent($name, EventInterface $event)
    {
        $this->event[$name] = $event; 
    }

    /**
     * @param EventInterface $event
     * @return string
     */
    protected function sendEvent($event)
    {
        $message = '';

        if ($this->settings['sendEventName']) {
            $message .= "\nevent: {$event->name()}";
        }

        $message .= "\ndata: {$event->data()}\nid: {$this->eventId}\n";

        $this->render($message);

        $this->eventId++;
    }

    /**
     * remove timer event handler
     *
     * @param string $name the event name
     * @return void
     */
    public function removeEvent($name)
    {
        unset($this->events[$name]);
    }

    /**
     *
     * @return void
     */
    public function run()
    {
        $this->setup();
        $this->loop->run();
    }

    /**
     * Setup System
     *
     * @return void
     */
    protected function setup()
    {
        if ($limit = $this->settings['execLimit']) {
            $this->loop->addTimer($limit, function () {
                $this->loop->stop();
            });
        }

        $this->loop->futureTick(function () {
            $this->startedTime = new DateTime();

            $this->sendHeader();

            if ($this->settings['retryTime']) {
                $retryTime = $this->settings['retryTime'] * 1000;
                $this->render("retry: $retryTime\n");
            }
        });

        foreach ($this->events as $event) {
            switch ($event->type()) {
                case EventInterface::TYPE_TIMER:
                    $this->loop->addPeriodicTimer($event->interval(), function () use ($event) {
                        if ($event->ready()) {
                            $this->sendEvent($event);
                        }
                    });
                    break;
                case EventInterface::TYPE_START:
                    $this->loop->futureTick(function () use ($event) {
                        if ($event->ready()) {
                            $this->sendEvent($event);
                        }
                    });
                    break;
            }
        }

        if ($interval = $this->settings['keepAliveInterval']) {
            $this->loop->addPeriodicTimer($interval, function () {
                $this->render("\n: {$this->keepMessage}\n");
            }); 
        }

        @ob_end_clean();
    }

    protected function render($message)
    {
        echo $message;

        @ob_flush();
        @flush();
    }

    /**
     * @return void
     */
    protected function sendHeader()
    {
        foreach ($this->response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        header(sprintf(
            'HTTP/%s %s %s',
            $this->response->getProtocolVersion(),
            $this->response->getStatusCode(),
            $this->response->getReasonPhrase()
        ));
    }

    public function settings($key = null, $value = null)
    {
        if (is_null($key)) {
            return $this->settings;
        }

        if (is_array($key)) {
            $this->settings = array_merge($this->settings, $key);
        } elseif (is_null($value)) {
            return $this->settings[$key];
        } else {
            $this->settings[$key] = $value;
        }
    }

    /**
     * @param LoopInterface|null
     * @return mixed
     */
    public function loop($loop = null)
    {
        if (!$loop instanceof LoopInterface) {
            return $this->loop;
        }

        $this->loop = $loop;
    }

    /**
     * @param string|null
     * @return mixed
     */
    public function keepMessage($message = null)
    {
        if (is_null($message)) {
            return $this->keepMessage;
        }

        $this->keepMessage = (string) $message;
    }

    /**
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }
}