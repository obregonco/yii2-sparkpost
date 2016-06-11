<?php
/**
 * Mailer class.
 *
 * @copyright Copyright (c) 2016 Danil Zakablukovskii
 * @package djagya/yii2-sparkpost
 * @author Danil Zakablukovskii <danil.kabluk@gmail.com>
 */

namespace djagya\sparkpost;

use Ivory\HttpAdapter\Configuration;
use Ivory\HttpAdapter\CurlHttpAdapter;
use SparkPost\APIResponseException;
use SparkPost\SparkPost;
use yii\base\InvalidConfigException;
use yii\BaseYii;
use yii\helpers\ArrayHelper;
use yii\mail\BaseMailer;

/**
 * Mailer consumes Message object and sends it through Sparkpost API.
 *
 * @see Message
 * @author Danil Zakablukovskii <danil.kabluk@gmail.com>
 */
class Mailer extends BaseMailer
{
    const LOG_CATEGORY = 'sparkpost-mailer';

    /**
     * Allowed limit for send retries, positive integer.
     * If limit is reached, last error will be thrown.
     * @var int
     */
    public $retryLimit = 5;

    /**
     * As a default http adapter will be used CurlHttpAdapter.
     * @var array|callable|string
     */
    public $httpAdapter;

    /**
     * @var string SparkPost API Key, required.
     */
    public $apiKey;

    /**
     * Whether to use the sandbox mode.
     * You can send up to 50 messages.
     * You must set your 'from' address as a sandbox domain.
     * @var bool sandbox mode
     */
    public $sandbox = false;

    /**
     * Additional SparkPost config
     * @see \SparkPost\SparkPost::$apiDefaults
     * @var array sparkpost config
     */
    public $sparkpostConfig = [];

    /**
     * Whether to use default email for 'from' and 'reply to' fields if they are empty.
     * @var bool default email usage
     */
    public $useDefaultEmail = true;

    /**
     * Default sender email.
     * If not specified, application name + params[adminEmail] will be used.
     * @var string default email
     */
    public $defaultEmail;

    /**
     * If the development mode is enabled, Mailer will throw an exception if something goes wrong.
     * If the development mode is disabled, Mailer will fail gracefully.
     * @var bool
     */
    public $developmentMode = true;

    /**
     * @inheritdoc
     */
    public $messageClass = 'djagya\sparkpost\Message';

    /** @var int amount of sent messages last 'sendMessage' call */
    public $sentCount = 0;
    /** @var int amount of rejected messages last 'sendMessage' call */
    public $rejectedCount = 0;
    /** @var string last transaction id */
    public $lastTransmissionId;
    /** @var null|APIResponseException last transmission exception (if any) */
    public $lastError;

    /** @var SparkPost */
    private $_sparkPost;

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->apiKey) {
            throw new InvalidConfigException('"' . get_class($this) . '::apiKey" must be set.');
        }

        if (!is_string($this->apiKey)) {
            throw new InvalidConfigException('"' . get_class($this) . '::apiKey" must be a string, ' .
                gettype($this->apiKey) . ' given.');
        }

        $this->sparkpostConfig['key'] = $this->apiKey;

        // Initialize the http adapter, cUrl adapter is default
        $adapterConfig = new Configuration();
        $adapterConfig->setTimeout(4);
        $httpAdapter = $this->httpAdapter ? BaseYii::createObject($this->httpAdapter) : new CurlHttpAdapter($adapterConfig);
        $this->_sparkPost = new SparkPost($httpAdapter, $this->sparkpostConfig);

        if ($this->useDefaultEmail && !$this->defaultEmail) {
            if (!isset(\Yii::$app->params['adminEmail'])) {
                throw new InvalidConfigException('You must set "' . get_class($this) .
                    '::defaultEmail" or have "adminEmail" key in application params or disable  "' . get_class($this) .
                    '::useDefaultEmail"');
            }

            $this->defaultEmail = \Yii::$app->name . '<' . \Yii::$app->params['adminEmail'] . '>';
        }
    }

    /**
     * Creates a new message instance and optionally composes its body content via view rendering.
     *
     * @param string|array $view the view to be used for rendering the message body. This can be:
     *
     * - a string, which represents the view name or path alias for rendering the HTML body of the email.
     *   In this case, the text body will be generated by applying `strip_tags()` to the HTML body.
     * - an array with ('html' and/or 'text' elements) OR 'template' element. The 'html' element refers to the view name or path alias
     *   for rendering the HTML body, while 'text' element is for rendering the text body. For example,
     *   `['html' => 'contact-html', 'text' => 'contact-text']`.
     *   If 'template' key is set, then stored template will be used for email.
     * - null, meaning the message instance will be returned without body content.
     *
     * The view to be rendered can be specified in one of the following formats:
     *
     * - path alias (e.g. "@app/mail/contact");
     * - a relative view name (e.g. "contact") located under [[viewPath]].
     *
     * @param array $params the parameters (name-value pairs) that will be extracted and made available in the view file or template.
     * @return Message message instance.
     */
    public function compose($view = null, array $params = [])
    {
        if (is_array($view) && isset($view['template'])) {
            /** @var Message $message */
            $message = parent::compose();
            $message->setTemplateId($view['template']);
        } else {
            $message = parent::compose($view, $params);
        }

        // make given params also available as substitution data
        $message->setSubstitutionData($params);

        if ($this->sandbox) {
            $message->setSandbox(true);
        }

        // set default message sender email
        if ($this->useDefaultEmail) {
            if (!$message->getFrom()) {
                $message->setFrom($this->defaultEmail);
            }
            if (!$message->getReplyTo()) {
                $message->setReplyTo($this->defaultEmail);
            }
        }

        return $message;
    }

    /**
     * Refer to the error codes descriptions to see details.
     *
     * @link https://support.sparkpost.com/customer/en/portal/articles/2140916-extended-error-codes Errors descriptions
     * @param Message $message
     * @return bool
     * @throws APIResponseException
     */
    protected function sendMessage($message)
    {
        // Clear info about last transmission.
        $this->lastTransmissionId = $this->lastError = null;
        $this->sentCount = $this->rejectedCount = 0;

        if (!$message->getTo()) {
            \Yii::warning('Message was not sent, because "to" recipients list is empty', self::LOG_CATEGORY);

            return false;
        }

        $attemptsCount = 0;
        while ($attemptsCount <= $this->retryLimit) {
            $attemptsCount++;

            try {
                return $this->internalSend($message);
            } catch (APIResponseException $e) {
                $this->lastError = $e;
            }
        }

        // Transmission wasn't sent.
        \Yii::error("An error occurred in mailer: {$this->lastError->getMessage()}, code: {$this->lastError->getAPICode()}, api message: \"{$this->lastError->getAPIMessage()}\", api description: \"{$this->lastError->getAPIDescription()}\"",
            self::LOG_CATEGORY);

        if ($this->developmentMode) {
            throw $this->lastError;
        } else {
            return false;
        }
    }

    /**
     * @param Message $message
     * @return bool
     */
    protected function internalSend($message)
    {
        $result = $this->_sparkPost->transmission->send($message->toSparkPostArray());
        $this->lastTransmissionId = ArrayHelper::getValue($result, 'results.id');

        // Rejected messages.
        $this->rejectedCount = ArrayHelper::getValue($result, 'results.total_rejected_recipients');
        if ($this->rejectedCount > 0) {
            \Yii::info("Transmission #{$this->lastTransmissionId}: {$this->rejectedCount} rejected",
                self::LOG_CATEGORY);
        }

        // Sent messages.
        $this->sentCount = ArrayHelper::getValue($result, 'results.total_accepted_recipients');
        if ($this->sentCount === 0) {
            \Yii::info("Transmission #{$this->lastTransmissionId} was rejected: all {$this->rejectedCount} rejected",
                self::LOG_CATEGORY);

            return false;
        }

        return true;
    }

    /**
     * @return SparkPost
     */
    public function getSparkPost()
    {
        return $this->_sparkPost;
    }
}
