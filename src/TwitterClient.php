<?php

namespace Noweh\TwitterApi;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\HandlerStack;
use JsonException;
use Noweh\TwitterApi\Exception\TooManyRequestException;
use stdClass;

class TwitterClient
{
    protected const API_BASE_URI = 'https://api.twitter.com/2/';
    protected string $bearer_token;
    protected string $endpoint;

    public function __construct(        protected readonly string $consumerKey,
                                        protected readonly string $consumerSecret,
                                        protected readonly string $accessToken,
                                        protected readonly string $accessTokenSecret)
    {
    }

    public function setBearerToken(string $token): void
    {
        $this->bearer_token = $token;
    }

    public function getMe()
    {
        $this->endpoint = 'users/me';
        return $this->performRequest();
    }

    public function deleteLike(int $userId, int $tweetId)
    {
        $this->endpoint = 'users/'.$userId.'/likes/'. $tweetId;
        return $this->performRequest('DELETE');
    }

    function getLikedTweets(int $userId, ?string $nextToken = null)
    {
        $this->endpoint = 'users/'.$userId.'/liked_tweets?tweet.fields=author_id';

        if ($nextToken) {
            $this->endpoint .= '&pagination_token='. $nextToken;
        }

        return $this->performRequest();
    }

    public function getExpandedTweet(array $ids)
    {
        $this->endpoint = 'tweets?ids='. join(',',$ids) .'&expansions=referenced_tweets.id,author_id,attachments.media_keys&tweet.fields=created_at&user.fields=id,name,username,profile_image_url&media.fields=url,type,width,height,preview_image_url,variants';

        return $this->performRequest();
    }

    /**
     * Perform the request to Twitter API
     * @param string $method
     * @param array<string, mixed> $postData
     * @return mixed
     * @throws GuzzleException
     * @throws JsonException
     * @throws Exception
     * @throws TooManyRequestException
     */
    public function performRequest(string $method = 'GET', array $postData = []): mixed
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
            if ($method === 'GET') {
                // Inject the Bearer token into the header for the call
                $client = new Client(['base_uri' => self::API_BASE_URI]);

                $headers['Authorization'] = 'Bearer ' . $this->bearer_token;

                // if GET method with id set, fetch tweet with id
                if (is_array($postData) && isset($postData['id']) && is_numeric($postData['id'])) {
                    $this->endpoint .= '/'.$postData['id'];
                    // unset to avoid clash later.
                    unset($postData['id']);
                }
            } else {
                // Inject Oauth handler
                $stack = HandlerStack::create();
                $middleware = new Oauth1([
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                    'token' => $this->accessToken,
                    'token_secret' => $this->accessTokenSecret,
                ]);
                $stack->push($middleware);

                $client = new Client([
                    'base_uri' => self::API_BASE_URI,
                    'handler' => $stack,
                    'auth' => 'oauth'
                ]);
            }

            $response  = $client->request($method, $this->endpoint, [
                'headers' => $headers,
                        // this is always array from function spec,use count to see if data set. Otherwise twitter error on empty data.
                'json'    => count($postData) ? $postData: null,
            ]);

            $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if ($response->getStatusCode() >= 400) {
                $error = new stdClass();
                $error->message = 'cURL error';
                if ($body) {
                    $error->details = $response;
                }
                throw new Exception(
                    json_encode($error, JSON_THROW_ON_ERROR),
                    $response->getStatusCode()
                );
            }

            return $body;
        } catch (ClientException | ServerException $e) {
            if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 429) {
                $resetTimestamp = $e->getResponse()->getHeader('x-rate-limit-reset')[0];

                throw new TooManyRequestException(json_encode([
                    'error' => 'Too many requests',
                    'timestamp' => $resetTimestamp,
                    'uri' => $this->endpoint
                ]));
            }

            throw new Exception(json_encode($e->getResponse()->getBody()->getContents(), JSON_THROW_ON_ERROR));
        }
    }

}
