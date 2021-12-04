<?php
namespace Slack;

use Devristo\Phpws\Client\WebSocket;
use Devristo\Phpws\Messaging\WebSocketMessageInterface;
use Evenement\EventEmitterTrait;
use React\Promise;
use Slack\Message\Message;

/**
 * A client for the Slack real-time messaging API.
 */
class RealTimeClient extends ApiClient
{
    use EventEmitterTrait;

    /**
     * @var WebSocket A websocket connection to the Slack API.
     */
    protected $websocket;

    /**
     * @var int The ID of the last payload sent to Slack.
     */
    protected $lastMessageId = 0;

    /**
     * @var array An array of pending messages waiting for successful confirmation
     *            from Slack.
     */
    protected $pendingMessages = [];

    /**
     * @var bool Indicates if the client is connected.
     */
    protected $connected = false;

    /**
     * @var Team The team logged in to.
     */
    protected $team;

    /**
     * @var array A map of users.
     */
    protected $users = [];

    /**
     * @var array A map of channels.
     */
    protected $channels = [];

    /**
     * @var array A map of groups.
     */
    protected $groups = [];

    /**
     * @var array A map of direct message channels.
     */
    protected $dms = [];

    /**
     * @var array A map of bots.
     */
    protected $bots = [];

    /**
     * Connects to the real-time messaging server.
     *
     * @param bool $batchPresenceAware wether or not to allow subscribing to batch precense changes. If you set this,
     *                                 you can then use $slackClient->subscribeToPresence(['blahblah']);
     *                                 to subscribe to the presence events
     * @return \React\Promise\PromiseInterface
     */
    public function connect($batchPresenceAware = false)
    {
        $deferred = new Promise\Deferred();

        // Request a real-time connection...
        $this->apiCall('rtm.connect', ['batch_presence_aware' => $batchPresenceAware, 'presence_sub' => $batchPresenceAware])

        // then connect to the socket...
        ->then(function (Payload $response) {
            $responseData = $response->getData();
            // get the team info
            $this->team = new Team($this, $responseData['team']);

            // Populate self user.
            $this->users[$responseData['self']['id']] = new User($this, $responseData['self']);

            // Save websocket url.
            $this->websocketUrl = $responseData['url'];
        })
        ->then(function () {
            // populate list of users
            $users = $this->getUsers();
            foreach ($users as $user) {
                $this->users[$user['id']] = $user;
            }
        })
        ->then(function () {
            // populate list of channels
            $channels = $this->getChannels();
            foreach ($channels as $channel) {
                $this->channels[$channel['id']] = $channel;
            }
        })
        ->then(function () {
            // populate list of groups
            $groups = $this->getGroups();
            foreach ($groups as $group) {
                $this->groups[$group['id']] = $group;
            }
        })
        ->then(function () {
            // populate list of dms
            $dms = $this->getDMs();
            foreach ($dms as $dm) {
                $this->dms[$dm['id']] = $dm;
            }
        })
        ->then(function () {
            // populate list of bots
            $bots = $this->getBots();
            foreach ($bots as $bot) {
                $this->bots[$bot['id']] = $bot;
            }
        })
        ->then (function () {
            // Log PHPWS things to stderr
            $logger = new \Zend\Log\Logger();
            $logger->addWriter(new \Zend\Log\Writer\Stream('php://stderr'));

            // initiate the websocket connection
            $this->websocket = new WebSocket($this->websocketUrl, $this->loop, $logger);
            $this->websocket->on('message', function ($message) {
                $this->onMessage($message);
            });

            return $this->websocket->open();
        }, function($exception) use ($deferred) {
            // if connection was not succesfull
            $deferred->reject(new ConnectionException(
                'Could not connect to Slack API: '. $exception->getMessage(),
                $exception->getCode()
            ));
        })

        // then wait for the connection to be ready.
        ->then(function () use ($deferred) {
            $this->once('hello', function () use ($deferred) {
                $deferred->resolve();
            });

            $this->once('error', function ($data) use ($deferred) {
                $deferred->reject(new ConnectionException(
                    'Could not connect to WebSocket: '.$data['error']['msg'],
                    $data['error']['code']));
            });
        });

        return $deferred->promise();
    }

    /**
     * Disconnects the client.
     */
    public function disconnect()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        $this->websocket->close();
        $this->connected = false;
    }

    /**
     * {@inheritDoc}
     */
    public function getTeam()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        return Promise\resolve($this->team);
    }

    /**
     * {@inheritDoc}
     */
    public function getChannels()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        return Promise\resolve(array_values($this->channels));
    }

    /**
     * {@inheritDoc}
     */
    public function getChannelById($id)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        if (!isset($this->channels[$id])) {
            return Promise\reject(new ApiException("No channel exists for ID '$id'."));
        }

        return Promise\resolve($this->channels[$id]);
    }

    /**
     * {@inheritDoc}
     */
    public function getGroups()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        return Promise\resolve(array_values($this->groups));
    }

    /**
     * {@inheritDoc}
     */
    public function getGroupById($id)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        if (!isset($this->groups[$id])) {
            return Promise\reject(new ApiException("No group exists for ID '$id'."));
        }

        return Promise\resolve($this->groups[$id]);
    }

    public function ping($pingID)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        $data = [
             'id' => ++$this->lastMessageId,
            'type' => 'ping',
            'pingID' => $pingID
        ];
        $this->websocket->send(json_encode($data));

        // Create a deferred object and add ping to pending list so when a
        // success message arrives, we can de-queue it and resolve the promise.
        $deferred = new Promise\Deferred();
        $this->pendingMessages[$this->lastMessageId] = $deferred;

        return $deferred->promise();
    }

    /**
     * {@inheritDoc}
     */
    public function getDMs()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        return Promise\resolve(array_values($this->dms));
    }

    /**
     * {@inheritDoc}
     */
    public function getDMById($id)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        if (!isset($this->dms[$id])) {
            return Promise\reject(new ApiException("No DM exists for ID '$id'."));
        }

        return Promise\resolve($this->dms[$id]);
    }

    /**
     * {@inheritDoc}
     */
    public function getUsers()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        return Promise\resolve(array_values($this->users));
    }

    /**
     * {@inheritDoc}
     */
    public function getUserById($id)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        if (!isset($this->users[$id])) {
            return Promise\reject(new ApiException("No user exists for ID '$id'."));
        }

        return Promise\resolve($this->users[$id]);
    }

    /**
     * Gets all bots in the Slack team.
     *
     * @return \React\Promise\PromiseInterface A promise for an array of bots.
     */
    public function getBots()
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        return Promise\resolve(array_values($this->bots));
    }

    /**
     * Gets a bot by its ID.
     *
     * @param string $id A bot ID.
     *
     * @return \React\Promise\PromiseInterface A promise for a bot object.
     */
    public function getBotById($id)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        if (!isset($this->bots[$id])) {
            return Promise\reject(new ApiException("No bot exists for ID '$id'."));
        }

        return Promise\resolve($this->bots[$id]);
    }

    /**
     * {@inheritDoc}
     */
    public function postMessage(Message $message)
    {
        if (!$this->connected) {
            return Promise\reject(new ConnectionException('Client not connected. Did you forget to call `connect()`?'));
        }

        // We can't send attachments using the RTM API, so revert to the web API
        // to send the message
        if ($message->hasAttachments()) {
            return parent::postMessage($message);
        }

        $data = [
            'id' => ++$this->lastMessageId,
            'type' => 'message',
            'channel' => $message->data['channel'],
            'text' => $message->getText(),
        ];
        $this->websocket->send(json_encode($data));

        // Create a deferred object and add message to pending list so when a
        // success message arrives, we can de-queue it and resolve the promise.
        $deferred = new Promise\Deferred();
        $this->pendingMessages[$this->lastMessageId] = $deferred;

        return $deferred->promise();
    }

    /**
     * Returns whether the client is connected.
     *
     * @return bool
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * Subscribe to presence change events for the given users
     *
     * @param string[] $userIDs
     */
    public function subscribeToPresence($userIDs)
    {
        $this->websocket->send(json_encode(['type' => 'presence_sub', 'ids' => $userIDs]));
    }

    /**
     * Handles incoming websocket messages, parses them, and emits them as remote events.
     *
     * @param WebSocketMessageInterface $messageRaw A websocket message.
     */
    private function onMessage(WebSocketMessageInterface $message)
    {
        // parse the message and get the event name
        $payload = Payload::fromJson($message->getData());

        if (isset($payload['type']) && $payload['type'] != 'pong') {
            switch ($payload['type']) {
                case 'hello':
                    $this->connected = true;
                    break;

                case 'team_rename':
                    $this->team->data['name'] = $payload['name'];
                    break;

                case 'team_domain_change':
                    $this->team->data['domain'] = $payload['domain'];
                    break;

                case 'channel_joined':
                    $channel = new Channel($this, $payload['channel']);
                    $this->channels[$channel->getId()] = $channel;
                    break;

                case 'channel_created':
                    $this->getChannelById($payload['channel']['id'])->then(function (Channel $channel) {
                        $this->channels[$channel->getId()] = $channel;
                    });
                    break;

                case 'channel_deleted':
                    unset($this->channels[$payload['channel']['id']]);
                    break;

                case 'channel_rename':
                    $this->channels[$payload['channel']['id']]->data['name']
                        = $payload['channel']['name'];
                    break;

                case 'channel_archive':
                    $this->channels[$payload['channel']]->data['is_archived'] = true;
                    break;

                case 'channel_unarchive':
                    $this->channels[$payload['channel']]->data['is_archived'] = false;
                    break;

                case 'group_joined':
                    $group = new Group($this, $payload['channel']);
                    $this->groups[$group->getId()] = $group;
                    break;

                case 'group_rename':
                    $this->groups[$payload['group']['id']]->data['name']
                        = $payload['channel']['name'];
                    break;

                case 'group_archive':
                    $this->groups[$payload['group']['id']]->data['is_archived'] = true;
                    break;

                case 'group_unarchive':
                    $this->groups[$payload['group']['id']]->data['is_archived'] = false;
                    break;

                case 'im_created':
                    $dm = new DirectMessageChannel($this, $payload['channel']);
                    $this->dms[$dm->getId()] = $dm;
                    break;

                case 'bot_added':
                    $bot = new Bot($this, $payload['bot']);
                    $this->bots[$bot->getId()] = $bot;
                    break;

                case 'bot_changed':
                    $bot = new Bot($this, $payload['bot']);
                    $this->bots[$bot->getId()] = $bot;
                    break;
            }

            // emit an event with the attached json
            $this->emit($payload['type'], [$payload]);
        } else {
            // If reply_to is set, then it is a server confirmation for a previously
            // sent message
            if (isset($payload['reply_to'])) {
                if (isset($this->pendingMessages[$payload['reply_to']])) {
                    $deferred = $this->pendingMessages[$payload['reply_to']];

                    // Resolve or reject the promise that was waiting for the reply.
                    if( $payload['type'] == 'pong' ){
                        $deferred->resolve($payload['pingID']);
                    } else {
                        // Resolve or reject the promise that was waiting for the reply.
                        if (isset($payload['ok']) && $payload['ok'] === true) {
                            $deferred->resolve();
                        } else {
                            $deferred->reject($payload['error']);
                        }
                    }

                    unset($this->pendingMessages[$payload['reply_to']]);
                }
            }
        }
    }
}
