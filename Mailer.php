<?php
/**
 * Mailer class.
 *
 * @copyright Copyright (c) 2016 Danil Zakablukovskii
 * @package djagya/yii2-sparkpost
 * @author Danil Zakablukovskii <danil.kabluk@gmail.com>
 */

namespace obregonco\sparkpost;

use GuzzleHttp\Client;
use Ivory\HttpAdapter\Guzzle6HttpAdapter;
use SparkPost\APIResponseException;
use SparkPost\SparkPost;
use yii\base\InvalidConfigException;
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
     * @inheritdoc
     */
    public $messageClass = 'djagya\sparkpost\Message';

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

        $httpAdapter = new Guzzle6HttpAdapter(new Client());
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
     * @throws \Exception
     */
    protected function sendMessage($message)
    {
        try {
            $result = $this->_sparkPost->transmission->send($message->toSparkPostArray());

            if (ArrayHelper::getValue($result, 'total_accepted_recipients') === 0) {
                \Yii::info('Transmission #' . ArrayHelper::getValue($result, 'id') . ' was rejected: ' .
                    ArrayHelper::getValue($result, 'total_rejected_recipients') . ' rejected',
                    self::LOG_CATEGORY);

                return false;
            }

            if (ArrayHelper::getValue($result, 'total_rejected_recipients') > 0) {
                \Yii::info('Transmission #' . ArrayHelper::getValue($result, 'id') . ': ' .
                    ArrayHelper::getValue($result, 'total_rejected_recipients') . ' rejected',
                    self::LOG_CATEGORY);
            }

            return true;
        } catch (APIResponseException $e) {
            \Yii::error($e->getMessage(), self::LOG_CATEGORY);
            throw new \Exception('An error occurred in mailer, check your application logs.', 500, $e);
        }
    }

    /**
     * @return SparkPost
     */
    public function getSparkPost()
    {
        return $this->_sparkPost;
    }
}
