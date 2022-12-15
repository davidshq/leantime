<?php
/**
 * Generates an JSON-RPC API
 */

namespace leantime\domain\controllers;

use leantime\core\controller;

class jsonrpc extends controller
{

    /**
     * init - initialize private variables or events to happen before route execution
     *
     * @return void
     */
    public function init()
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * Handles post requests
     *
     * @param array $params - value of $_POST
     *
     * @return void
     */
    public function post($params): void
    {
        $this->executeApiRequest($params);
    }

    /**
     * Handles get requests
     *
     * @param array $params - value of $_GET
     *
     * @return void
     */
    public function get($params): void
    {
        if (!isset($params['q'])) {
            $this->returnInvalidRequest("you must encode your request body in JSON and place it in a \"q\" query parameter when using GET");
        }

        $params = (array) json_decode($params['q'], JSON_OBJECT_AS_ARRAY);

        if ($params == null) {
            $this->returnParseError('JSON is invalid and was not able to be parsed');
        }

        $this->executeApiRequest($params);
    }

    /**
     * Handles patch requests
     *
     * @return void
     */
    public function patch(): void
    {
        $this->returnInvalidRequest('The JSON-RPC API only supports POST/GET requests');
    }

    /**
     * Handles delete requests
     *
     * @return void
     */
    public function delete(): void
    {
        $this->returnInvalidRequest('The JSON-RPC API only supports POST/GET requests');
    }

    /**
     * post - executes api call
     *
     * @param string $service - service method name
     * @param array $arguments - [$request_method, $function, $parameters]
     *
     * @return void
     */
    private function executeApiRequest($params): void
    {

        $methodparts = $this->parseMethodString(isset($params['method']) ? $params['method'] : '');
        $jsonRpcVer = isset($params['jsonrpc']) ? $params['jsonrpc'] : null;
        $serviceName = "leantime\\domain\\services\\{$methodparts['service']}";
        $methodName = $methodparts['method'];
        $paramsFromRequest = isset($params['params']) ? $params['params'] : [];
        $id = isset($params['id']) ? $params['id'] : null;

        if (!class_exists($serviceName)) {
            $this->returnMethodNotFound("Service doesn't exist: $serviceName");
        }

        if (!method_exists($serviceName, $methodName)) {
            $this->returnMethodNotFound("Method doesn't exist: $methodName");
        }

        if ($jsonRpcVer == null) {
            $this->returnInvalidRequest("You must include a \"jsonrpc\" parameter with a value of \"2.0\"");
        }

        if ($jsonRpcVer !== '2.0') {
            $this->returnInvalidRequest("Leantime only supports JSON-RPC version 2.0");
        }

        $methodParams = $this->getMethodParameters($serviceName, $methodName);
        $preparedParams = $this->prepareParameters($paramsFromRequest, $methodParams);

        // can be null
        try {
            $method_response = (new $serviceName())->$methodName($preparedParams);
        } catch (Error $e) {
            $this->returnServerError($e);
        }

        if ($method_response !== null) {
            if (!settype($method_response, 'array')) {
                $method_response = [$method_response];
            }
        }

        $this->returnResponse($method_response, $id);
    }

    /**
     * Parses the method string
     *
     * @param string $methodstring - leantime.rpc.service.method
     */
    private function parseMethodString(string $methodstring): array
    {
        if (empty($methodstring)) {
            $this->returnInvalidRequest("Must include method");
        }

        if (!str_starts_with($methodstring, "leantime.rpc.")) {
            $this->returnInvalidRequest("Method string doesn't start with \"leantime.rpc.\"");
        }

        $methodStringPieces = explode('.', $methodstring);
        if (count($methodStringPieces) !== 4) {
            $this->returnInvalidRequest("Method is case sensitive and must follow the following naming convention: \"leantime.rpc.{servicename}.{methodname}\"");
        }

        return [
            'service' => $methodStringPieces[2],
            'method' => $methodStringPieces[3]
        ];
    }

    /**
     * Gets the Method Parameters
     *
     * @param string $servicename
     * @param string $methodname
     *
     * @return \ReflectionParameter[]
     */
    private function getMethodParameters(string $servicename, string $methodname): array
    {
        $reflectionParameters = (new \ReflectionClass($servicename))
            ->getMethod($methodname)
            ->getParameters();

        return $reflectionParameters;
    }

    /**
     * Checks request params
     *
     * @param array $params
     * @param \ReflectionParameter[] $methodParams
     *
     * @return array
     */
    private function prepareParameters(array $params, array $methodParams): array
    {
        $filtered_parameters = [];

        // matches params, params that don't match are ignored
        foreach ($methodParams as $methodparam) {
            $required = $methodparam->isDefaultValueAvailable() ? false : true;
            $position = $methodparam->getPosition();
            $name = $methodparam->name;
            $type = $methodparam->getType();

            // check if param is there
            if (!in_array($name, array_keys($params))) {
                if ($required) {
                    $this->returnInvalidParams("Required Parameter Missing: $name");
                }

                $filtered_parameters[$position] = $methodparam->getDefaultValue();
                continue;
            }

            // check if type is correct or can be correct
            if ($methodparam->hasType()) {
                if ($type == gettype($params[$name])) {
                    $filtered_parameters[$position] = $params[$name];
                    continue;
                }

                if (settype($params[$name], $type)) {
                    $filtered_parameters[$position] = $params[$name];
                    continue;
                }

                $this->returnInvalidParams("Incorrect Type on Parameter: $name");
            }

            $filtered_parameters[$position] = $params[$name];
        }

        // make sure its in the right order
        ksort($filtered_parameters);

        return $filtered_parameters;
    }

    /**
     * Echos the return response
     *
     * @param array|null $returnValue
     * @param string $requestMethod
     *
     * @return void
     */
    private function returnResponse(array|null $returnValue, string $id = null): void
    {
        echo json_encode([
            'jsonrpc' => '2.0',
            'message' => "Request was successful",
            'result' => $returnValue,
            'id' => $id
        ]);
    }

    /**
     * Return error response
     *
     * @param string $errorMessage
     * @param int $httpResponseCode
     *
     * @return void
     */
    private function returnError(string $errorMessage, int $errorcode, mixed $additional_info = null): void
    {
        echo json_encode([
            'code' => $errorcode,
            'message' => $errorMessage,
            'data' => $additional_info
        ]);
        exit;
    }

    /**
     * Returns a parse error
     *
     * @see https://jsonrpc.org/specification#error_object
     *
     * @param mixed|null $additional_info
     *
     * @return void
     */
    private function returnParseError(mixed $additional_info = null): void
    {
        $this->returnError('Parse error', -32700, $additional_info);
    }

    /**
     * Returns an invalid request error
     *
     * @see https://jsonrpc.org/specification#error_object
     *
     * @param mixed|null $additional_info
     *
     * @return void
     */
    private function returnInvalidRequest(mixed $additional_info = null): void
    {
        $this->returnError('Invalid Request', -32600, $additional_info);
    }

    /**
     * Returns a method not found error
     *
     * @see https://jsonrpc.org/specification#error_object
     *
     * @param mixed|null $additional_info
     *
     * @return void
     */
    private function returnMethodNotFound(mixed $additional_info = null): void
    {
        $this->returnError('Method not found', -32601, $additional_info);
    }

    /**
     * Returns an invalid parameters error
     *
     * @see https://jsonrpc.org/specification#error_object
     *
     * @param mixed|null $additional_info
     *
     * @return void
     */
    private function returnInvalidParams(mixed $additional_info = null): void
    {
        $this->returnError('Invalid params', -32602, $additional_info);
    }

    /**
     * Returns a server error
     *
     * @see https://jsonrpc.org/specification#error_object
     *
     * @param mixed|null $additional_info
     *
     * @return void
     */
    private function returnServerError(mixed $additional_info): void
    {
        $this->returnError('Server error', -32000, $additional_info);
    }
}
