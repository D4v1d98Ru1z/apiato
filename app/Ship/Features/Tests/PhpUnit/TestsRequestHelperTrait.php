<?php

namespace App\Ship\Features\Tests\PhpUnit;

use App;
use App\Ship\Features\Exceptions\MissingTestEndpointException;
use App\Ship\Features\Exceptions\UndefinedMethodException;
use App\Ship\Features\Exceptions\WrongEndpointFormatException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;

/**
 * Class TestsRequestHelperTrait
 *
 * Tests helper for making HTTP requests.
 *
 * @author  Mahmoud Zalt  <mahmoud@zalt.me>
 */
trait TestsRequestHelperTrait
{

    /**
     * property to be set on the user test class
     *
     * @var  string
     */
    protected $endpoint = '';

    /**
     * property to be set on the user test class
     *
     * @var  bool
     */
    protected $auth = true;

    /**
     * property to be set before making a call to override the default class property
     *
     * @var string
     */
    protected $overrideEndpoint;

    /**
     * property to be set before making a call to override the default class property
     *
     * @var string
     */
    protected $overrideAuth;

    /**
     * the $endpoint property will be extracted to $endpointVerb and $endpointUri after parsing
     *
     * @var string
     */
    private $endpointVerb;

    /**
     * the $endpoint property will be extracted to $endpointVerb and $endpointUri after parsing
     *
     * @var string
     */
    private $endpointUri;

    /**
     * @param array $data
     * @param array $headers
     *
     * @return  mixed
     */
    public function makeCall(array $data = [], array $headers = [])
    {
        // read the $endpoint property from the test and set the verb and the uri as properties on this trait
        $this->parseEndpoint();
        $verb = $this->endpointVerb;
        $url = $this->buildUrlForUri($this->endpointUri);

        $headers = $this->injectAccessToken($headers);

        switch ($verb) {
            case 'put':
            case 'patch':
            case 'delete':
            case 'post':
                $headers = $this->transformHeadersToServerVars($headers);
                $httpResponse = $this->call($verb, $url, $data, [], [], $headers);
                break;
            case 'get':
                $url = $this->dataArrayToQueryParam($data, $url);
                $headers = $this->transformHeadersToServerVars($headers);
                $httpResponse = $this->call($verb, $url, [], [], [], $headers);
                break;
            case 'json:post':
            case 'json:put':
            case 'json:patch':
            case 'json:delete':
            case 'json:get':
                $verbName = $this->getJsonVerb($verb);
                $httpResponse = $this->json($verbName, $url, $data, $headers);
                break;
            default:
                throw new UndefinedMethodException('Undefined HTTP Verb (' . $verb . ').');
        }

        return $httpResponse;
    }

    /**
     * Transform headers array to array of $_SERVER vars with HTTP_* format.
     *
     * @param  array  $headers
     * @return array
     */
    protected function transformHeadersToServerVars(array $headers)
    {
        return collect($headers)->mapWithKeys(function ($value, $name) {
            $name = strtr(strtoupper($name), '-', '_');

            return [$this->formatServerHeaderKey($name) => $value];
        })->all();
    }

    /**
     * Inject the ID in the Endpoint URI before making the call by
     * overriding the `$this->endpoint` property
     *
     * Example: you give it ('users/{id}/stores', 100) it returns 'users/100/stores'
     *
     * @param        $id
     * @param bool   $skipEncoding
     * @param string $replace
     *
     * @return  $this
     */
    public function injectId($id, $skipEncoding = false, $replace = '{id}')
    {
        // In case Hash ID is enabled it will encode the ID first
        $id = $this->hashEndpointId($id, $skipEncoding);
        $this->endpoint = str_replace($replace, $id, $this->endpoint);

        return $this;
    }

    /**
     * Override the default class endpoint property before making the call
     *
     * to be used as follow: $this->endpoint('verb@uri')->makeCall($data);
     *
     * @param $endpoint
     *
     * @return  $this
     */
    public function endpoint($endpoint)
    {
        $this->overrideEndpoint = $endpoint;

        return $this;
    }

    /**
     * Override the default class auth property before making the call
     *
     * to be used as follow: $this->auth('false')->makeCall($data);
     *
     * @param bool $auth
     *
     * @return  $this
     */
    public function auth(bool $auth)
    {
        $this->overrideAuth = $auth;

        return $this;
    }

    /**
     * @return  string
     */
    public function getEndpoint()
    {
        return !is_null($this->overrideEndpoint) ? $this->overrideEndpoint : $this->endpoint;
    }

    /**
     * @return  bool
     */
    public function getAuth()
    {
        return !is_null($this->overrideAuth) ? $this->overrideAuth : $this->auth;
    }

    /**
     * @param $uri
     *
     * @return  string
     */
    private function buildUrlForUri($uri)
    {
        // add `/` at the beginning in case it doesn't exist
        if (!Str::startsWith($uri, '/')) {
            $uri = '/'.$uri;
        }

        return Config::get('apiato.api.url') . $uri;
    }

    /**
     * Attach Authorization Bearer Token to the request headers
     * if it doesn't exist already and the authentication is required
     * for the endpoint `$this->auth = true`.
     *
     * @param $headers
     *
     * @return  mixed
     */
    private function injectAccessToken(array $headers = [])
    {
        // if endpoint is protected (requires token to access it's functionality)
        if ($this->getAuth() && !$this->headersContainAuthorization($headers)) {
            // append the token to the header
            $headers['Authorization'] = 'Bearer ' . $this->getTestingUser()->token;
        }

        return $headers;
    }

    /**
     * just check if headers array has an `Authorization` as key.
     *
     * @param $headers
     *
     * @return  bool
     */
    private function headersContainAuthorization($headers)
    {
        return array_has($headers, 'Authorization');
    }

    /**
     * @param $data
     * @param $url
     *
     * @return  string
     */
    private function dataArrayToQueryParam($data, $url)
    {
        return $data ? $url . '?' . http_build_query($data) : $url;
    }

    /**
     * @param $text
     *
     * @return  string
     */
    private function getJsonVerb($text)
    {
        return Str::replaceFirst('json:', '', $text);
    }


    /**
     * @param      $id
     * @param bool $skipEncoding
     *
     * @return  mixed
     */
    private function hashEndpointId($id, $skipEncoding = false)
    {
        return (Config::get('apiato.hash-id') && !$skipEncoding) ? Hashids::encode($id) : $id;
    }

    /**
     * read `$this->endpoint` property (`verb@uri`) and get `$this->endpointVerb` & `$this->endpointUri`
     */
    private function parseEndpoint()
    {
        $this->validateEndpointExist();

        $separator = '@';

        $this->validateEndpointFormat($separator);

        // convert the string to array
        $asArray = explode($separator, $this->getEndpoint(), 2);

        // get the verb and uri values from the array
        extract(array_combine(['verb', 'uri'], $asArray));

        /** @var TYPE_NAME $verb */
        $this->endpointVerb = $verb;
        /** @var TYPE_NAME $uri */
        $this->endpointUri = $uri;
    }

    /**
     * @void
     */
    private function validateEndpointExist()
    {
        if (!$this->getEndpoint()) {
            throw new MissingTestEndpointException();
        }
    }

    /**
     * @param $separator
     */
    private function validateEndpointFormat($separator)
    {
        // check if string contains the separator
        if (!strpos($this->getEndpoint(), $separator)) {
            throw new WrongEndpointFormatException();
        }
    }
}
