<?php

namespace losthost\SimpleAI;

use losthost\SimpleAI\data\DBContext;
use losthost\SimpleAI\types\Context;
use losthost\SimpleAI\types\ContextItem;
use losthost\DB\DBValue;
use losthost\DB\DBView;
use losthost\SimpleAI\types\Response;
use losthost\SimpleAI\data\DBStatistics;
use losthost\SimpleAI\types\Tools;
use losthost\SimpleAI\types\abstract\AbstractAITool;

class SimpleAIAgent {

    const DEFAULT_PROMPT = '';
    const DEFAULT_TEMPERATURE = 1.0;
    const DEFAULT_MAX_TOKENS = 4096;
    const DEFAULT_TIMEOUT = 30;

    const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
    const DEFAULT_MODEL = 'gpt-5-nano'; // поменяешь, если захочешь жирнее

    protected string $openai_api_key;
    protected string $model;

    protected string $user_id;
    protected string $dialog_id;

    protected bool $logging;
    protected int $timeout;

    protected string $prompt;
    protected float $temperature;
    protected int $max_tokens;

    protected Tools $tools;

    public function __construct(string $openai_api_key) {
        $this->openai_api_key = $openai_api_key;
        $this->logging = false;
        $this->timeout = static::DEFAULT_TIMEOUT;
        $this->prompt = static::DEFAULT_PROMPT;
        $this->temperature = static::DEFAULT_TEMPERATURE;
        $this->max_tokens = static::DEFAULT_MAX_TOKENS;
        $this->dialog_id = '';
        $this->agent_name = '';
        $this->tools = Tools::create();
        $this->model = static::DEFAULT_MODEL;
    }

    static public function build(string $openai_api_key) : static {
        return new static($openai_api_key);
    }

    public function setModel(string $model) : static {
        $this->model = $model;
        return $this;
    }

    public function setPrompt(string $prompt) : static {
        $this->prompt = $prompt;
        return $this;
    }

    public function getPrompt(): string {
        return $this->prompt;
    }

    public function setTemperature(float $temperature) : static {
        $this->temperature = $temperature;
        return $this;
    }

    public function getTemperature(): float {
        return $this->temperature;
    }

    public function setMaxTokens(int $max_tokens) : static {
        $this->max_tokens = $max_tokens;
        return $this;
    }

    public function getMaxTokens() : int {
        return $this->max_tokens;
    }

    public function setUserId(string $user_id) : static {
        $this->user_id = $user_id;
        return $this;
    }

    public function setDialogId(string $dialog_id) : static {
        $this->dialog_id = $dialog_id;
        return $this;
    }

    public function ask(?string $query=null, bool|string|callable $handle_errors=false) : Context {

        try {
            return $this->dispatchQuery($query);
        } catch (\Throwable $ex) {
            if ($handle_errors === false) {
                throw $ex;
            } elseif ($handle_errors === true) {
                return Context::create()
                        ->add(ContextItem::create($ex->getMessage(), ContextItem::ROLE_ERROR));
            } elseif (is_callable($handle_errors)) {
                $answer = $handle_errors($ex);
                return is_string($answer)
                        ? Context::create()
                            ->add(ContextItem::create($answer, ContextItem::ROLE_ERROR))
                        : $answer;
            } elseif (is_string($handle_errors)) {
                return Context::create()->add(ContextItem::create($handle_errors, ContextItem::ROLE_ERROR));
            }
        }
    }

    protected function dispatchQuery(?string $query) : Context {
        if (empty($this->user_id)) {
            return $this->simpleQuery($query);
        } else {
            return $this->contextQuery($query);
        }
    }

    protected function simpleQuery(string $query) : Context  {

        $context = Context::create([
                ContextItem::create($this->getPrompt(), ContextItem::ROLE_SYSTEM),
                ContextItem::create($query)
        ]);

        $answer_context = Context::create();
        $this->processContext($context, $answer_context);

        return $answer_context;
    }

    protected function contextQuery(?string $query) : Context {

        $context = $this->getContext($query);

        $answer_context = Context::create();
        $this->processContext($context, $answer_context);

        $this->historyAdd($answer_context);

        return $answer_context;
    }

    protected function processContext(Context $history, Context &$new) {

        $context = Context::create($history->asArray());
        foreach ($new->asArray() as $item) {
            $context->add($item);
        }

        $response = $this->postQuery($context);

        if ($response->hasContent()) {
            $new->add(ContextItem::create($response->getContent(), ContextItem::ROLE_ASSISTANT));
        }

        if ($response->hasToolCall()) {
            foreach ($response->getToolCalls() as $tool_call) {
                $handler = AbstractAITool::getHandler($tool_call->getName());
                $result = $handler->execute($tool_call->getArgs());
                $new->add(ContextItem::create(
                    $result->getResult(),
                    ContextItem::ROLE_TOOL,
                    $tool_call->getId()
                ));
                $this->processContext($history, $new);
            }
        }
    }

    // =======================
    // 🔥 Главное мясо: OpenAI
    // =======================

    protected function postQuery(Context $context) : Response {

        $messages = [];

        foreach ($context->asArray() as $item) {
            if ($item->getRole() === ContextItem::ROLE_TOOL) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => $item->getToolCall(),
                ];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $item->getToolCallId(),
                    'content' => $item->getContent()
                ];
            } elseif ($item->getRole() === ContextItem::ROLE_ASSISTANT && $item->getToolCall()) {
                $messages[] = [
                    'role' => 'assistant',
                    'tool_calls' => [$item->getToolCall()]
                ];
            } else {
                $messages[] = [
                    'role' => $item->getRole(),
                    'content' => $item->getContent()
                ];
            }
        }

        $tools = [];
        foreach ($this->tools->asArray() as $tool) {
            $tools[] = $tool->getDefinition();
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
        ];

        // 👇 вот тут магия совместимости
        if (preg_match('/^gpt-5/', $this->model)) {
            $payload['max_completion_tokens'] = $this->max_tokens;
        } else {
            $payload['max_tokens'] = $this->max_tokens;
        }
        
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $raw = $this->httpPostJson(static::OPENAI_API_URL, $payload);

        $decoded = json_decode($raw, true);
        if (!$decoded) {
            throw new \RuntimeException("Invalid JSON from OpenAI: $raw");
        }

        if (!empty($decoded['error'])) {
            throw new \RuntimeException($decoded['error']['message'] ?? 'OpenAI API error');
        }

        $response = Response::fromResponse($decoded);

        if (isset($this->user_id)) {
            $this->collectStatistics($response);
        }

        return $response;
    }

    protected function httpPostJson(string $url, array $payload) : string {

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openai_api_key,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: $err");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("OpenAI HTTP $status: $response");
        }

        return $response;
    }

    // =======================
    // История и БД
    // =======================

    protected function historyAdd(Context $new) : void {
        foreach ($new->asArray() as $item) {
            DBContext::add(
                $this->user_id,
                $this->dialog_id,
                $item->getRole(),
                $item->getContent(),
                $item->getToolCallId()
            );
        }
    }

    protected function getContextView(string $user_id, string $dialog_id) : DBView {
        return new DBView(<<<FIN
                SELECT role, content, tool_call_id 
                FROM [sai_context] 
                WHERE user_id = ? AND dialog_id = ? 
                ORDER BY id
                FIN, [$user_id, $dialog_id]);
    }

    protected function getContext($query) : Context {

        if (!$this->hasContext()) {
            $this->makeContext();
        }

        if ($query) {
            DBContext::add($this->user_id, $this->dialog_id, 'user', $query);
        }

        $context_view = $this->getContextView($this->user_id, $this->dialog_id);

        $context = new Context();
        while ($context_view->next()) {
            $context->add(
                ContextItem::create(
                    $context_view->content,
                    $context_view->role,
                    $context_view->tool_call_id
                )
            );
        }

        return $context;
    }

    protected function hasContext() {
        DBContext::initDataStructure();
        $context = new DBValue(<<<FIN
                SELECT COUNT(*) AS messages 
                FROM [sai_context] 
                WHERE user_id = ? AND dialog_id = ? 
                FIN, [$this->user_id, $this->dialog_id]);

        return (bool)$context->messages;
    }

    protected function makeContext() {
        $prompt = $this->getPrompt();
        if (!empty($prompt)) {
            DBContext::add($this->user_id, $this->dialog_id, 'system', $prompt);
        }
    }

    protected function collectStatistics(Response $response) : void {

        DBStatistics::add(
            $response->getId(),
            $response->getCreated(),
            $this->user_id,
            $this->dialog_id,
            $response->getPromptTokens(),
            $response->getCompletionTokens()
        );
    }

    public function addTool(AbstractAITool $tool) : static {
        $this->tools->add($tool);
        return $this;
    }

    public function setTimeout(int $timeout) : static {
        $this->timeout = $timeout;
        return $this;
    }

    public function setLogging(bool $enable) : static {
        if (!$enable) {
            $this->log("Logging disabled");
        }
        $this->logging = $enable;
        $this->log("Logging enabled");
        return $this;
    }

    protected function log(mixed $what_to_log, ?string $file_name=null, ?int $line_number=null) {
        if (!$this->logging) {
            return;
        }

        if (is_string($what_to_log)) {
            $log_message = "$what_to_log";
            if ($file_name) {
                $log_message .= " in $file_name";
            }
            if ($line_number) {
                $log_message .= " ($line_number)";
            }
        } else {
            $log_message = print_r($what_to_log, true);
            if ($file_name) {
                $log_message .= "\n in $file_name";
            }
            if ($line_number) {
                $log_message .= " ($line_number)";
            }
        }

        $m = [];
        preg_match("/(\w+)$/", static::class, $m);
        error_log($m[1]. ": ". $log_message);
    }
}
