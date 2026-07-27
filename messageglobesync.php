<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

// Bundled MessageGlobe PHP SDK (REST: SMS, Contacts, Lists, Senders). Email is
// handled by the dependency-free MessageGlobeSmtpMailer below, not the SDK.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Service/MessageGlobeSmtpMailer.php';

class Messageglobesync extends Module
{
    public const CONFIG_ACCESS_TOKEN = 'MESSAGEGLOBE_ACCESS_TOKEN';
    public const CONFIG_GROUP_ID = 'MESSAGEGLOBE_GROUP_ID';
    public const CONFIG_CRON_TOKEN = 'MESSAGEGLOBE_CRON_TOKEN';
    public const CONFIG_EMAIL_OVERRIDE = 'MESSAGEGLOBE_EMAIL_OVERRIDE';
    public const CONFIG_SMTP_HOST = 'MESSAGEGLOBE_SMTP_HOST';
    public const CONFIG_SMTP_PORT = 'MESSAGEGLOBE_SMTP_PORT';
    public const CONFIG_SMTP_ENCRYPTION = 'MESSAGEGLOBE_SMTP_ENCRYPTION';
    public const CONFIG_SMTP_USER = 'MESSAGEGLOBE_SMTP_USER';
    public const CONFIG_SMTP_PASSWORD = 'MESSAGEGLOBE_SMTP_PASSWORD';
    public const CONFIG_SMTP_FROM = 'MESSAGEGLOBE_SMTP_FROM';
    public const CONFIG_SMTP_FROM_NAME = 'MESSAGEGLOBE_SMTP_FROM_NAME';
    public const DEFAULT_SMTP_HOST = 'dashboard.messageglobe.com';
    public const DEFAULT_SMTP_PORT = '465';
    public const DEFAULT_SMTP_ENCRYPTION = 'ssl';
    public const TABLE_CONTACT_MAP = 'messageglobe_contact_map';
    public const TABLE_SYNC_QUEUE = 'messageglobe_sync_queue';
    public const TABLE_CRON_LOG = 'messageglobe_cron_log';
    public const MAX_QUEUE_ATTEMPTS = 5;

    // ── SMS ────────────────────────────────────────────────────────────────
    public const CONFIG_SMS_ENABLED = 'MESSAGEGLOBE_SMS_ENABLED';
    public const CONFIG_SMS_SENDER_ID = 'MESSAGEGLOBE_SMS_SENDER_ID';
    public const CONFIG_SMS_GATEWAY = 'MESSAGEGLOBE_SMS_GATEWAY';
    public const CONFIG_SMS_CUSTOMER_ENABLED = 'MESSAGEGLOBE_SMS_CUSTOMER_ENABLED';
    public const CONFIG_SMS_STATE_MAP = 'MESSAGEGLOBE_SMS_STATE_MAP';
    public const CONFIG_SMS_ADMIN_ENABLED = 'MESSAGEGLOBE_SMS_ADMIN_ENABLED';
    public const CONFIG_SMS_ADMIN_RECIPIENTS = 'MESSAGEGLOBE_SMS_ADMIN_RECIPIENTS';
    public const CONFIG_SMS_ADMIN_TEMPLATE = 'MESSAGEGLOBE_SMS_ADMIN_TEMPLATE';
    public const DEFAULT_SMS_GATEWAY = 'HQ';
    public const DEFAULT_SMS_ADMIN_TEMPLATE = 'New order {order_reference} — {total_paid} ({payment})';
    public const TABLE_SMS_QUEUE = 'messageglobe_sms_queue';
    public const TABLE_SMS_LOG = 'messageglobe_sms_log';
    public const MAX_SMS_ATTEMPTS = 3;

    public function __construct()
    {
        $this->name = 'messageglobesync';
        $this->tab = 'administration';
        $this->version = '1.3.1';
        $this->author = 'Message Globe SRL';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Message Globe for PrestaShop');
        $this->description = $this->l('Sync customers to Message Globe, send transactional SMS (order-status updates and admin alerts), and optionally route store email through the Message Globe SMTP relay.');
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        $this->setEmailDefaults();
        $this->setSmsDefaults();

        return parent::install()
            && $this->installDatabase()
            && $this->registerHook('actionObjectCustomerAddAfter')
            && $this->registerHook('actionObjectCustomerUpdateAfter')
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        Configuration::deleteByName(static::CONFIG_ACCESS_TOKEN);
        Configuration::deleteByName(static::CONFIG_GROUP_ID);
        Configuration::deleteByName(static::CONFIG_CRON_TOKEN);
        Configuration::deleteByName(static::CONFIG_EMAIL_OVERRIDE);
        Configuration::deleteByName(static::CONFIG_SMTP_HOST);
        Configuration::deleteByName(static::CONFIG_SMTP_PORT);
        Configuration::deleteByName(static::CONFIG_SMTP_ENCRYPTION);
        Configuration::deleteByName(static::CONFIG_SMTP_USER);
        Configuration::deleteByName(static::CONFIG_SMTP_PASSWORD);
        Configuration::deleteByName(static::CONFIG_SMTP_FROM);
        Configuration::deleteByName(static::CONFIG_SMTP_FROM_NAME);
        Configuration::deleteByName(static::CONFIG_SMS_ENABLED);
        Configuration::deleteByName(static::CONFIG_SMS_SENDER_ID);
        Configuration::deleteByName(static::CONFIG_SMS_GATEWAY);
        Configuration::deleteByName(static::CONFIG_SMS_CUSTOMER_ENABLED);
        Configuration::deleteByName(static::CONFIG_SMS_STATE_MAP);
        Configuration::deleteByName(static::CONFIG_SMS_ADMIN_ENABLED);
        Configuration::deleteByName(static::CONFIG_SMS_ADMIN_RECIPIENTS);
        Configuration::deleteByName(static::CONFIG_SMS_ADMIN_TEMPLATE);

        return $this->uninstallDatabase() && parent::uninstall();
    }

    protected function setEmailDefaults()
    {
        if (Configuration::get(static::CONFIG_SMTP_HOST) === false) {
            Configuration::updateValue(static::CONFIG_SMTP_HOST, static::DEFAULT_SMTP_HOST);
        }
        if (Configuration::get(static::CONFIG_SMTP_PORT) === false) {
            Configuration::updateValue(static::CONFIG_SMTP_PORT, static::DEFAULT_SMTP_PORT);
        }
        if (Configuration::get(static::CONFIG_SMTP_ENCRYPTION) === false) {
            Configuration::updateValue(static::CONFIG_SMTP_ENCRYPTION, static::DEFAULT_SMTP_ENCRYPTION);
        }
    }

    protected function setSmsDefaults()
    {
        if (Configuration::get(static::CONFIG_SMS_GATEWAY) === false) {
            Configuration::updateValue(static::CONFIG_SMS_GATEWAY, static::DEFAULT_SMS_GATEWAY);
        }
        if (Configuration::get(static::CONFIG_SMS_ADMIN_TEMPLATE) === false) {
            Configuration::updateValue(static::CONFIG_SMS_ADMIN_TEMPLATE, static::DEFAULT_SMS_ADMIN_TEMPLATE);
        }
    }

    public function getContent()
    {
        // Ensures new tables/hooks/defaults are provisioned when an already-installed
        // module is upgraded by uploading a new zip.
        $this->installDatabase();
        $this->setEmailDefaults();
        $this->setSmsDefaults();
        $this->registerHook('actionEmailSendBefore');
        $this->registerHook('actionValidateOrder');
        $this->registerHook('actionOrderStatusPostUpdate');

        // Remove hooks earlier versions registered but this version no longer
        // uses (address changes and customer deletion no longer sync), so a
        // module upgraded in place stops reacting to them. Idempotent.
        $this->unregisterHook('actionObjectCustomerDeleteAfter');
        $this->unregisterHook('actionObjectAddressAddAfter');
        $this->unregisterHook('actionObjectAddressUpdateAfter');
        $this->unregisterHook('actionObjectAddressDeleteAfter');

        if ((int) Tools::getValue('ajax') === 1 && Tools::getValue('messageglobe_action')) {
            $this->processAjaxRequest();
        }

        $output = '';

        if (Tools::isSubmit('submitMessageGlobeEmailConfig')) {
            Configuration::updateValue(static::CONFIG_EMAIL_OVERRIDE, (int) Tools::getValue(static::CONFIG_EMAIL_OVERRIDE));
            Configuration::updateValue(static::CONFIG_SMTP_HOST, trim((string) Tools::getValue(static::CONFIG_SMTP_HOST)));
            Configuration::updateValue(static::CONFIG_SMTP_PORT, (int) Tools::getValue(static::CONFIG_SMTP_PORT));
            Configuration::updateValue(static::CONFIG_SMTP_ENCRYPTION, (string) Tools::getValue(static::CONFIG_SMTP_ENCRYPTION));
            Configuration::updateValue(static::CONFIG_SMTP_USER, trim((string) Tools::getValue(static::CONFIG_SMTP_USER)));

            $submittedPassword = (string) Tools::getValue(static::CONFIG_SMTP_PASSWORD);
            if ($submittedPassword !== '') {
                Configuration::updateValue(static::CONFIG_SMTP_PASSWORD, $submittedPassword);
            }

            Configuration::updateValue(static::CONFIG_SMTP_FROM, trim((string) Tools::getValue(static::CONFIG_SMTP_FROM)));
            Configuration::updateValue(static::CONFIG_SMTP_FROM_NAME, trim((string) Tools::getValue(static::CONFIG_SMTP_FROM_NAME)));

            if ((int) Configuration::get(static::CONFIG_EMAIL_OVERRIDE) === 1 && !$this->isSmtpConfigured()) {
                $output .= $this->displayWarning($this->l('Email takeover is enabled but the SMTP host, username or password is missing. Emails will keep using PrestaShop until the SMTP settings are complete.'));
            }

            $output .= $this->displayConfirmation($this->l('Email settings updated successfully.'));
        }

        if (Tools::isSubmit('submitMessageGlobeSyncConfig')) {
            $accessToken = trim((string) Tools::getValue(static::CONFIG_ACCESS_TOKEN));
            $groupId = trim((string) Tools::getValue(static::CONFIG_GROUP_ID));
            $cronToken = trim((string) Tools::getValue(static::CONFIG_CRON_TOKEN));

            if ($accessToken === '' || $groupId === '') {
                $output .= $this->displayError($this->l('Access token and group ID are required.'));
            } else {
                if ($cronToken === '') {
                    $cronToken = $this->generateCronToken();
                }

                Configuration::updateValue(static::CONFIG_ACCESS_TOKEN, $accessToken);
                Configuration::updateValue(static::CONFIG_GROUP_ID, $groupId);
                Configuration::updateValue(static::CONFIG_CRON_TOKEN, $cronToken);

                $output .= $this->displayConfirmation($this->l('Settings updated successfully.'));
            }
        }

        if (Tools::isSubmit('submitMessageGlobeSyncQueueAll')) {
            if (!$this->isConfigured()) {
                $output .= $this->displayError($this->l('Please configure the access token and group ID before queueing customers.'));
            } else {
                $queued = $this->queueAllCustomers();
                $output .= $this->displayConfirmation(sprintf($this->l('Queued %d customers for cron sync.'), (int) $queued));
            }
        }

        if (Tools::isSubmit('submitMessageGlobeSyncClearQueue')) {
            $this->clearQueue();
            $output .= $this->displayConfirmation($this->l('Message Globe sync queue cleared.'));
        }

        if (Tools::isSubmit('submitMessageGlobeSmsConfig')) {
            Configuration::updateValue(static::CONFIG_SMS_ENABLED, (int) Tools::getValue(static::CONFIG_SMS_ENABLED));
            Configuration::updateValue(static::CONFIG_SMS_SENDER_ID, trim((string) Tools::getValue(static::CONFIG_SMS_SENDER_ID)));

            $gateway = (string) Tools::getValue(static::CONFIG_SMS_GATEWAY);
            Configuration::updateValue(static::CONFIG_SMS_GATEWAY, in_array($gateway, ['HQ', 'LQ'], true) ? $gateway : 'HQ');

            Configuration::updateValue(static::CONFIG_SMS_CUSTOMER_ENABLED, (int) Tools::getValue(static::CONFIG_SMS_CUSTOMER_ENABLED));
            Configuration::updateValue(static::CONFIG_SMS_ADMIN_ENABLED, (int) Tools::getValue(static::CONFIG_SMS_ADMIN_ENABLED));
            Configuration::updateValue(static::CONFIG_SMS_ADMIN_RECIPIENTS, trim((string) Tools::getValue(static::CONFIG_SMS_ADMIN_RECIPIENTS)));
            Configuration::updateValue(static::CONFIG_SMS_ADMIN_TEMPLATE, (string) Tools::getValue(static::CONFIG_SMS_ADMIN_TEMPLATE));

            if ((int) Configuration::get(static::CONFIG_SMS_ENABLED) === 1 && !$this->hasAccessToken()) {
                $output .= $this->displayWarning($this->l('SMS is enabled but the access token is not set. Add it in the Message Globe settings above.'));
            }

            $output .= $this->displayConfirmation($this->l('SMS settings updated successfully.'));
        }

        if (Tools::isSubmit('submitMessageGlobeSmsStates')) {
            $this->saveSmsStateMap();
            $output .= $this->displayConfirmation($this->l('Order-status SMS templates saved.'));
        }

        return $this->renderBrandHeader() . $output
            . $this->renderForm() . $this->renderEmailForm() . $this->renderEmailTestPanel() . $this->renderBulkSyncPanel()
            . $this->renderSmsForm() . $this->renderSmsStatePanel() . $this->renderSmsTestPanel() . $this->renderSmsLogPanel();
    }

    public function hookActionEmailSendBefore(array $params)
    {
        // Returning true lets PrestaShop continue with its own mailer.
        // Returning false tells PrestaShop the email was already handled here.
        if ((int) Configuration::get(static::CONFIG_EMAIL_OVERRIDE) !== 1 || !$this->isSmtpConfigured()) {
            return true;
        }

        try {
            $delivered = $this->sendEmailViaMessageGlobe($params);
        } catch (Exception $exception) {
            PrestaShopLogger::addLog(
                'Message Globe email takeover failed, falling back to PrestaShop mailer: ' . $exception->getMessage(),
                3,
                null,
                'Mail',
                null,
                true
            );

            return true;
        }

        return $delivered ? false : true;
    }

    protected function isSmtpConfigured()
    {
        return trim((string) Configuration::get(static::CONFIG_SMTP_HOST)) !== ''
            && trim((string) Configuration::get(static::CONFIG_SMTP_USER)) !== ''
            && (string) Configuration::get(static::CONFIG_SMTP_PASSWORD) !== '';
    }

    protected function sendEmailViaMessageGlobe(array $params)
    {
        $subject = (string) $this->getEmailParam($params, ['subject']);
        $template = (string) $this->getEmailParam($params, ['template']);
        $idLang = (int) $this->getEmailParam($params, ['id_lang', 'idLang']);
        $templatePath = (string) $this->getEmailParam($params, ['template_path', 'templatePath']);
        $templateVars = $this->getEmailParam($params, ['template_vars', 'templateVars']);
        $templateVars = is_array($templateVars) ? $templateVars : [];

        $htmlBody = $this->renderEmailBody($template, $idLang, $templatePath, $templateVars);
        if ($htmlBody === null || $htmlBody === '') {
            throw new Exception(sprintf('Unable to render mail template "%s".', $template));
        }

        // Use PrestaShop's matching .txt template for the plaintext MIME part when
        // it exists; otherwise the mailer derives plaintext from the HTML.
        $textBody = $this->renderEmailBody($template, $idLang, $templatePath, $templateVars, 'txt');

        $psFrom = (string) $this->getEmailParam($params, ['from']);
        $psFromName = (string) $this->getEmailParam($params, ['from_name', 'fromName']);
        $forcedFrom = trim((string) Configuration::get(static::CONFIG_SMTP_FROM));
        $forcedFromName = trim((string) Configuration::get(static::CONFIG_SMTP_FROM_NAME));

        $fromEmail = $forcedFrom !== '' ? $forcedFrom : $psFrom;
        $fromName = $forcedFromName !== '' ? $forcedFromName : $psFromName;
        if ($fromEmail === '') {
            $fromEmail = (string) Configuration::get('PS_SHOP_EMAIL');
        }

        $mailer = new MessageGlobeSmtpMailer([
            'host' => (string) Configuration::get(static::CONFIG_SMTP_HOST),
            'port' => (int) Configuration::get(static::CONFIG_SMTP_PORT),
            'encryption' => (string) Configuration::get(static::CONFIG_SMTP_ENCRYPTION),
            'username' => (string) Configuration::get(static::CONFIG_SMTP_USER),
            'password' => (string) Configuration::get(static::CONFIG_SMTP_PASSWORD),
        ]);

        $mailer->setFrom($fromEmail, $fromName);

        $to = $this->getEmailParam($params, ['to']);
        $toName = $this->getEmailParam($params, ['to_name', 'toName']);
        $this->addRecipientsToMailer($mailer, $to, $toName);

        $replyTo = (string) $this->getEmailParam($params, ['reply_to', 'replyTo']);
        $replyToName = (string) $this->getEmailParam($params, ['reply_to_name', 'replyToName']);
        if ($replyTo !== '') {
            $mailer->setReplyTo($replyTo, $replyToName);
        }

        $bcc = $this->getEmailParam($params, ['bcc']);
        if (!empty($bcc)) {
            foreach ((array) $bcc as $bccAddress) {
                if (is_string($bccAddress) && $bccAddress !== '') {
                    $mailer->addBcc($bccAddress);
                }
            }
        }

        $this->addAttachmentsToMailer($mailer, $this->getEmailParam($params, ['file_attachment', 'fileAttachment']));

        return $mailer->send($subject, $htmlBody, $textBody);
    }

    /**
     * @param array<int,string> $keys
     * @return mixed
     */
    protected function getEmailParam(array $params, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                return $params[$key];
            }
        }

        return null;
    }

    protected function addRecipientsToMailer(MessageGlobeSmtpMailer $mailer, $to, $toName)
    {
        if (is_array($to)) {
            foreach ($to as $index => $address) {
                if (!is_string($address) || $address === '') {
                    continue;
                }
                $name = '';
                if (is_array($toName) && isset($toName[$index]) && is_string($toName[$index])) {
                    $name = $toName[$index];
                } elseif (is_string($toName)) {
                    $name = $toName;
                }
                $mailer->addAddress($address, $name);
            }

            return;
        }

        if (is_string($to) && $to !== '') {
            $mailer->addAddress($to, is_string($toName) ? $toName : '');
        }
    }

    protected function addAttachmentsToMailer(MessageGlobeSmtpMailer $mailer, $fileAttachment)
    {
        if (empty($fileAttachment) || !is_array($fileAttachment)) {
            return;
        }

        // A single attachment is an associative array; multiple are a list of them.
        $attachments = isset($fileAttachment['content']) ? [$fileAttachment] : $fileAttachment;

        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !isset($attachment['content'])) {
                continue;
            }

            $mailer->addAttachment(
                $attachment['content'],
                isset($attachment['name']) ? (string) $attachment['name'] : 'attachment',
                isset($attachment['mime']) ? (string) $attachment['mime'] : 'application/octet-stream'
            );
        }
    }

    protected function renderEmailBody($template, $idLang, $templatePath, array $templateVars, $extension = 'html')
    {
        $file = $this->locateMailTemplate($template, $idLang, $templatePath, $extension);
        if ($file === null) {
            return null;
        }

        $html = Tools::file_get_contents($file);
        if ($html === false || $html === '') {
            return null;
        }

        $vars = array_merge($this->getDefaultMailVars(), $templateVars);

        // PrestaShop mail templates use {token} placeholders replaced by value.
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $search[] = $key;
                $replace[] = (string) $value;
            }
        }

        return str_replace($search, $replace, $html);
    }

    protected function locateMailTemplate($template, $idLang, $templatePath, $extension = 'html')
    {
        $template = str_replace(['..', '/', '\\'], ['', '', ''], (string) $template);
        if ($template === '') {
            return null;
        }

        $extension = ltrim(strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $extension)), '.');
        if ($extension === '') {
            $extension = 'html';
        }

        $iso = 'en';
        if ($idLang > 0) {
            $langIso = Language::getIsoById($idLang);
            if ($langIso) {
                $iso = strtolower($langIso);
            }
        }

        $candidates = [];
        $templatePath = (string) $templatePath;
        if ($templatePath !== '') {
            $base = rtrim($templatePath, '/') . '/';
            $candidates[] = $base . $iso . '/' . $template . '.' . $extension;
            $candidates[] = $base . 'en/' . $template . '.' . $extension;
        }

        if (defined('_PS_MAIL_DIR_')) {
            $candidates[] = _PS_MAIL_DIR_ . $iso . '/' . $template . '.' . $extension;
            $candidates[] = _PS_MAIL_DIR_ . 'en/' . $template . '.' . $extension;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function getDefaultMailVars()
    {
        $shopUrl = '';
        if (isset($this->context->shop) && Validate::isLoadedObject($this->context->shop)) {
            $shopUrl = (Tools::usingSecureMode() ? 'https://' : 'http://') . $this->context->shop->domain . $this->context->shop->getBaseURI();
        }

        return [
            '{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'),
            '{shop_url}' => $shopUrl,
            '{shop_email}' => (string) Configuration::get('PS_SHOP_EMAIL'),
            '{color}' => (string) Configuration::get('PS_MAIL_COLOR'),
        ];
    }

    protected function renderBrandHeader()
    {
        $logo = $this->_path . 'views/img/messageglobe-logo.png';
        $icon = $this->_path . 'views/img/messageglobe-icon.png';
        $name = htmlspecialchars($this->displayName, ENT_QUOTES, 'UTF-8');
        $version = htmlspecialchars($this->version, ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars($this->author, ENT_QUOTES, 'UTF-8');
        $tagline = htmlspecialchars($this->l('Reach them everywhere'), ENT_QUOTES, 'UTF-8');
        $byline = htmlspecialchars(sprintf($this->l('by %s'), $this->author), ENT_QUOTES, 'UTF-8');

        return '
            <div class="messageglobe-brand-header" style="
                display:flex; align-items:center; gap:20px;
                background:linear-gradient(135deg,#5B86B3 0%,#4a7499 100%);
                border-radius:8px; padding:22px 26px; margin-bottom:20px;
                box-shadow:0 2px 8px rgba(74,116,153,0.25); color:#fff;">
                <div style="flex:0 0 auto; background:#fff; border-radius:14px; padding:10px;
                    box-shadow:0 1px 4px rgba(0,0,0,0.12); line-height:0;">
                    <img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"
                        alt="' . $author . '" width="60" height="60" style="display:block; border-radius:8px;">
                </div>
                <div style="flex:1 1 auto; min-width:0;">
                    <div style="font-size:20px; font-weight:700; line-height:1.2; letter-spacing:.2px;">' . $name . '</div>
                    <div style="font-size:13px; opacity:.9; margin-top:3px;">' . $tagline . '</div>
                    <div style="font-size:12px; opacity:.8; margin-top:8px;">' . $byline . ' &middot; v' . $version . '</div>
                </div>
                <div style="flex:0 0 auto; align-self:flex-start;">
                    <img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '"
                        alt="' . $author . '" style="height:34px; width:auto; display:block;
                        filter:brightness(0) invert(1); opacity:.95;">
                </div>
            </div>';
    }

    public function hookActionObjectCustomerAddAfter(array $params)
    {
        $this->handleCustomerHook($params);
    }

    public function hookActionObjectCustomerUpdateAfter(array $params)
    {
        $this->handleCustomerHook($params);
    }

    protected function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Message Globe settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Access token'),
                        'name' => static::CONFIG_ACCESS_TOKEN,
                        'required' => true,
                        'desc' => $this->l('Bearer token used to authenticate API calls to Message Globe.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Group ID'),
                        'name' => static::CONFIG_GROUP_ID,
                        'required' => true,
                        'desc' => $this->l('UID of the Message Globe contact list/group.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Cron token'),
                        'name' => static::CONFIG_CRON_TOKEN,
                        'required' => false,
                        'desc' => $this->l('Secret token required to run the cron sync URL. Leave empty and save to auto-generate one.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMessageGlobeSyncConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                static::CONFIG_ACCESS_TOKEN => Tools::getValue(static::CONFIG_ACCESS_TOKEN, Configuration::get(static::CONFIG_ACCESS_TOKEN)),
                static::CONFIG_GROUP_ID => Tools::getValue(static::CONFIG_GROUP_ID, Configuration::get(static::CONFIG_GROUP_ID)),
                static::CONFIG_CRON_TOKEN => Tools::getValue(static::CONFIG_CRON_TOKEN, Configuration::get(static::CONFIG_CRON_TOKEN)),
            ],
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    protected function renderEmailForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Email takeover (SMTP relay)'),
                    'icon' => 'icon-envelope',
                ],
                'description' => $this->l('When enabled, every email PrestaShop would normally send (order confirmations, account emails, newsletters, etc.) is delivered through the Message Globe SMTP relay instead. If a send fails, the module automatically falls back to PrestaShop\'s default mailer so no message is lost.'),
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Route outgoing emails through Message Globe'),
                        'name' => static::CONFIG_EMAIL_OVERRIDE,
                        'is_bool' => true,
                        'desc' => $this->l('Master toggle for the email takeover. Leave off to let PrestaShop send emails normally.'),
                        'values' => [
                            [
                                'id' => 'messageglobe_email_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'messageglobe_email_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('SMTP host'),
                        'name' => static::CONFIG_SMTP_HOST,
                        'required' => true,
                        'desc' => $this->l('Message Globe SMTP server, e.g. dashboard.messageglobe.com'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('SMTP port'),
                        'name' => static::CONFIG_SMTP_PORT,
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('465 for SSL/SMTPS, 587 for STARTTLS.'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Encryption'),
                        'name' => static::CONFIG_SMTP_ENCRYPTION,
                        'options' => [
                            'query' => [
                                ['id' => 'ssl', 'name' => $this->l('SSL / SMTPS (port 465)')],
                                ['id' => 'tls', 'name' => $this->l('STARTTLS (port 587)')],
                                ['id' => 'none', 'name' => $this->l('None (not recommended)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('SMTP username'),
                        'name' => static::CONFIG_SMTP_USER,
                        'required' => true,
                        'desc' => $this->l('The Message Globe SMTP login (usually an email address).'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('SMTP password'),
                        'name' => static::CONFIG_SMTP_PASSWORD,
                        'desc' => $this->l('Message Globe SMTP password or API token. Leave blank to keep the current password.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Force "From" address'),
                        'name' => static::CONFIG_SMTP_FROM,
                        'desc' => $this->l('Optional. Overrides the sender address on every email (relays often require the From address to match the authenticated domain). Leave blank to keep PrestaShop\'s sender.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Force "From" name'),
                        'name' => static::CONFIG_SMTP_FROM_NAME,
                        'desc' => $this->l('Optional. Overrides the sender display name on every email.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save email settings'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMessageGlobeEmailConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                static::CONFIG_EMAIL_OVERRIDE => (int) Tools::getValue(static::CONFIG_EMAIL_OVERRIDE, Configuration::get(static::CONFIG_EMAIL_OVERRIDE)),
                static::CONFIG_SMTP_HOST => Tools::getValue(static::CONFIG_SMTP_HOST, Configuration::get(static::CONFIG_SMTP_HOST)),
                static::CONFIG_SMTP_PORT => Tools::getValue(static::CONFIG_SMTP_PORT, Configuration::get(static::CONFIG_SMTP_PORT)),
                static::CONFIG_SMTP_ENCRYPTION => Tools::getValue(static::CONFIG_SMTP_ENCRYPTION, Configuration::get(static::CONFIG_SMTP_ENCRYPTION)),
                static::CONFIG_SMTP_USER => Tools::getValue(static::CONFIG_SMTP_USER, Configuration::get(static::CONFIG_SMTP_USER)),
                static::CONFIG_SMTP_PASSWORD => '',
                static::CONFIG_SMTP_FROM => Tools::getValue(static::CONFIG_SMTP_FROM, Configuration::get(static::CONFIG_SMTP_FROM)),
                static::CONFIG_SMTP_FROM_NAME => Tools::getValue(static::CONFIG_SMTP_FROM_NAME, Configuration::get(static::CONFIG_SMTP_FROM_NAME)),
            ],
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    protected function renderEmailTestPanel()
    {
        $ajaxUrl = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name
            . '&ajax=1';

        $ajaxUrlJson = json_encode($ajaxUrl);
        $labelsJson = json_encode([
            'testing' => $this->l('Testing SMTP connection...'),
            'failed' => $this->l('SMTP test failed.'),
        ]);
        $namesJson = json_encode([
            'host' => static::CONFIG_SMTP_HOST,
            'port' => static::CONFIG_SMTP_PORT,
            'encryption' => static::CONFIG_SMTP_ENCRYPTION,
            'user' => static::CONFIG_SMTP_USER,
            'password' => static::CONFIG_SMTP_PASSWORD,
        ]);

        return '
            <div class="panel">
                <h3><i class="icon-plug"></i> ' . $this->l('Test SMTP connection') . '</h3>
                <p class="help-block">' . $this->l('Connects to the SMTP server and authenticates using the settings in the form above, without sending an email. Values are tested as currently entered; leave the password blank to test the saved password.') . '</p>
                <button type="button" id="messageglobe-smtp-test" class="btn btn-default">
                    <i class="icon-plug"></i> ' . $this->l('Test connection') . '
                </button>
                <div id="messageglobe-smtp-test-status" class="alert" style="display:none; margin-top:12px;"></div>
            </div>
            <script>
            (function () {
                var ajaxUrl = ' . $ajaxUrlJson . ';
                var labels = ' . $labelsJson . ';
                var names = ' . $namesJson . ';
                var button = document.getElementById("messageglobe-smtp-test");
                var status = document.getElementById("messageglobe-smtp-test-status");

                if (!button) {
                    return;
                }

                function fieldValue(name) {
                    var el = document.querySelector(\'[name="\' + name + \'"]\');
                    return el ? el.value : "";
                }

                button.addEventListener("click", function () {
                    button.disabled = true;
                    status.style.display = "block";
                    status.className = "alert alert-info";
                    status.innerHTML = labels.testing;

                    var data = new FormData();
                    data.append("ajax", "1");
                    data.append("messageglobe_action", "smtp_test");
                    data.append(names.host, fieldValue(names.host));
                    data.append(names.port, fieldValue(names.port));
                    data.append(names.encryption, fieldValue(names.encryption));
                    data.append(names.user, fieldValue(names.user));
                    data.append(names.password, fieldValue(names.password));

                    fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: data })
                        .then(function (response) { return response.json(); })
                        .then(function (json) {
                            status.className = "alert " + (json.success ? "alert-success" : "alert-danger");
                            status.innerHTML = json.message || (json.success ? "OK" : labels.failed);
                        })
                        .catch(function (error) {
                            status.className = "alert alert-danger";
                            status.innerHTML = labels.failed + " " + error.message;
                        })
                        .then(function () {
                            button.disabled = false;
                        });
                });
            }());
            </script>';
    }

    protected function renderBulkSyncPanel()
    {
        $ajaxUrl = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name
            . '&ajax=1';

        $labels = [
            'starting' => $this->l('Starting sync...'),
            'running' => $this->l('Sync running...'),
            'completed' => $this->l('Sync completed.'),
            'failed' => $this->l('Sync failed.'),
            'processed' => $this->l('Processed'),
            'synced' => $this->l('Synced'),
            'errors' => $this->l('Errors'),
            'total' => $this->l('Total'),
        ];

        $ajaxUrlJson = json_encode($ajaxUrl);
        $labelsJson = json_encode($labels);
        $cronUrl = $this->getCronUrl();
        $queueStats = $this->getQueueStats();
        $cronLogsHtml = $this->renderCronLogs();

        return '
            <div class="panel">
                <h3><i class="icon-refresh"></i> ' . $this->l('Sync existing customers') . '</h3>
                <p>' . $this->l('Use this action to sync all existing PrestaShop customers, including past contacts, to Message Globe.') . '</p>
                <p class="help-block">' . $this->l('For very large stores, use the cron queue below. The AJAX sync is still available for manual runs from this page.') . '</p>
                <div class="alert alert-default">
                    <strong>' . $this->l('Queue status') . ':</strong>
                    ' . $this->l('Pending') . ': ' . (int) $queueStats['pending'] . ' |
                    ' . $this->l('Processing') . ': ' . (int) $queueStats['processing'] . ' |
                    ' . $this->l('Done') . ': ' . (int) $queueStats['done'] . ' |
                    ' . $this->l('Failed') . ': ' . (int) $queueStats['failed'] . '
                </div>
                <form method="post" action="' . htmlspecialchars($this->getModuleConfigUrl(), ENT_QUOTES, 'UTF-8') . '" style="margin-bottom:15px;">
                    <button type="submit" name="submitMessageGlobeSyncQueueAll" class="btn btn-default">
                        <i class="icon-list"></i> ' . $this->l('Queue all customers for cron sync') . '
                    </button>
                    <button type="submit" name="submitMessageGlobeSyncClearQueue" class="btn btn-warning" onclick="return confirm(\'' . htmlspecialchars($this->l('Clear all queued sync jobs?'), ENT_QUOTES, 'UTF-8') . '\');">
                        <i class="icon-trash"></i> ' . $this->l('Clear queue') . '
                    </button>
                </form>
                <div class="alert alert-info">
                    <p><strong>' . $this->l('Cron URL') . '</strong></p>
                    <code style="word-break: break-all; white-space: normal;">' . htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') . '</code>
                    <p class="help-block" style="margin-top: 8px;">' . $this->l('Recommended cron frequency: every minute. Each run processes a small batch and exits quickly.') . '</p>
                </div>
                <div class="alert alert-warning">
                    ' . $this->l('Automatic sync is queue-based: new or updated customers are added to the cron queue instead of being sent immediately. This keeps back-office and checkout requests fast.') . '
                </div>
                <div class="form-inline" style="margin-bottom: 15px;">
                    <label for="messageglobe-batch-size" style="margin-right: 8px;">' . $this->l('Batch size') . '</label>
                    <input type="number" id="messageglobe-batch-size" class="form-control" value="10" min="1" max="50" style="width: 90px; margin-right: 15px;">
                    <label for="messageglobe-concurrency" style="margin-right: 8px;">' . $this->l('Parallel requests') . '</label>
                    <input type="number" id="messageglobe-concurrency" class="form-control" value="4" min="1" max="8" style="width: 90px; margin-right: 15px;">
                    <button type="button" id="messageglobe-sync-start" class="btn btn-primary">
                        <i class="icon-refresh"></i> ' . $this->l('Sync all customers') . '
                    </button>
                </div>
                <div id="messageglobe-sync-status" class="alert alert-info" style="display:none;"></div>
                <div class="progress" id="messageglobe-sync-progress-wrap" style="display:none; height: 22px;">
                    <div id="messageglobe-sync-progress" class="progress-bar" role="progressbar" style="width:0%; line-height:22px;">0%</div>
                </div>
                ' . $cronLogsHtml . '
            </div>
            <script>
            (function () {
                var ajaxUrl = ' . $ajaxUrlJson . ';
                var labels = ' . $labelsJson . ';
                var button = document.getElementById("messageglobe-sync-start");
                var status = document.getElementById("messageglobe-sync-status");
                var progressWrap = document.getElementById("messageglobe-sync-progress-wrap");
                var progress = document.getElementById("messageglobe-sync-progress");
                var batchInput = document.getElementById("messageglobe-batch-size");
                var concurrencyInput = document.getElementById("messageglobe-concurrency");

                if (!button) {
                    return;
                }

                function request(params) {
                    var url = ajaxUrl;
                    Object.keys(params).forEach(function (key) {
                        url += "&" + encodeURIComponent(key) + "=" + encodeURIComponent(params[key]);
                    });

                    return fetch(url, { credentials: "same-origin" }).then(function (response) {
                        return response.json();
                    }).then(function (json) {
                        if (!json.success) {
                            throw new Error(json.message || labels.failed);
                        }

                        return json;
                    });
                }

                function updateStatus(text, stats) {
                    status.style.display = "block";
                    status.innerHTML = text;

                    if (stats) {
                        status.innerHTML += "<br>" + labels.processed + ": " + stats.processed + " / " + stats.total
                            + " | " + labels.synced + ": " + stats.synced
                            + " | " + labels.errors + ": " + stats.failed;
                    }
                }

                function updateProgress(processed, total) {
                    var percentage = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
                    progressWrap.style.display = "block";
                    progress.style.width = percentage + "%";
                    progress.innerHTML = percentage + "%";
                }

                button.addEventListener("click", function () {
                    var batchSize = Math.max(1, Math.min(50, parseInt(batchInput.value, 10) || 10));
                    var concurrency = Math.max(1, Math.min(8, parseInt(concurrencyInput.value, 10) || 4));
                    var nextOffset = 0;
                    var total = 0;
                    var active = 0;
                    var completed = false;
                    var stats = { processed: 0, synced: 0, failed: 0, total: 0 };

                    button.disabled = true;
                    batchInput.disabled = true;
                    concurrencyInput.disabled = true;
                    updateStatus(labels.starting);
                    updateProgress(0, 0);

                    request({ messageglobe_action: "sync_total" }).then(function (json) {
                        total = parseInt(json.total, 10) || 0;
                        stats.total = total;
                        updateStatus(labels.running, stats);
                        updateProgress(0, total);

                        return new Promise(function (resolve, reject) {
                            function launchNext() {
                                if (completed) {
                                    return;
                                }

                                while (active < concurrency && nextOffset < total) {
                                    var offset = nextOffset;
                                    nextOffset += batchSize;
                                    active++;

                                    request({
                                        messageglobe_action: "sync_batch",
                                        offset: offset,
                                        limit: batchSize
                                    }).then(function (batch) {
                                        stats.processed += parseInt(batch.processed, 10) || 0;
                                        stats.synced += parseInt(batch.synced, 10) || 0;
                                        stats.failed += parseInt(batch.failed, 10) || 0;
                                        updateStatus(labels.running, stats);
                                        updateProgress(stats.processed, total);
                                    }).then(function () {
                                        active--;
                                        if (stats.processed >= total && active === 0) {
                                            completed = true;
                                            resolve();
                                        } else {
                                            launchNext();
                                        }
                                    }).catch(function (error) {
                                        completed = true;
                                        reject(error);
                                    });
                                }

                                if (total === 0) {
                                    completed = true;
                                    resolve();
                                }
                            }

                            launchNext();
                        });
                    }).then(function () {
                        updateProgress(stats.processed, total);
                        updateStatus(labels.completed, stats);
                    }).catch(function (error) {
                        status.className = "alert alert-danger";
                        updateStatus(labels.failed + " " + error.message, stats);
                    }).then(function () {
                        button.disabled = false;
                        batchInput.disabled = false;
                        concurrencyInput.disabled = false;
                    });
                });
            }());
            </script>';
    }

    protected function processAjaxRequest()
    {
        header('Content-Type: application/json');

        $action = (string) Tools::getValue('messageglobe_action');

        // The SMTP and SMS tests are independent of the contact-sync group id, so
        // they are handled before the sync configuration gate below.
        if ($action === 'smtp_test') {
            $this->ajaxTestSmtp();
        }

        if ($action === 'sms_test') {
            $this->ajaxTestSms();
        }

        if (!$this->isConfigured()) {
            $this->ajaxResponse([
                'success' => false,
                'message' => $this->l('Please configure the access token and group ID before running a sync.'),
            ]);
        }

        if ($action === 'sync_total') {
            $this->ajaxResponse([
                'success' => true,
                'total' => $this->getCustomerCount(),
            ]);
        }

        if ($action === 'sync_batch') {
            $offset = max(0, (int) Tools::getValue('offset', 0));
            $limit = max(1, min(50, (int) Tools::getValue('limit', 10)));
            $result = $this->syncCustomerBatch($offset, $limit);
            $result['success'] = true;
            $result['offset'] = $offset;
            $result['limit'] = $limit;

            $this->ajaxResponse($result);
        }

        if ($action === 'queue_all') {
            $this->ajaxResponse([
                'success' => true,
                'queued' => $this->queueAllCustomers(),
            ]);
        }

        $this->ajaxResponse([
            'success' => false,
            'message' => $this->l('Unknown sync action.'),
        ]);
    }

    protected function ajaxResponse(array $payload)
    {
        echo json_encode($payload);
        exit;
    }

    /**
     * Validates the SMTP connection and credentials without sending an email.
     * Uses the values submitted from the config form, falling back to the saved
     * configuration for any field left blank (notably the password, which the
     * form never pre-fills).
     */
    protected function ajaxTestSmtp()
    {
        $host = trim((string) Tools::getValue(static::CONFIG_SMTP_HOST));
        if ($host === '') {
            $host = trim((string) Configuration::get(static::CONFIG_SMTP_HOST));
        }

        $port = (int) Tools::getValue(static::CONFIG_SMTP_PORT);
        if ($port <= 0) {
            $port = (int) Configuration::get(static::CONFIG_SMTP_PORT);
        }

        $encryption = (string) Tools::getValue(static::CONFIG_SMTP_ENCRYPTION);
        if ($encryption === '') {
            $encryption = (string) Configuration::get(static::CONFIG_SMTP_ENCRYPTION);
        }

        $username = trim((string) Tools::getValue(static::CONFIG_SMTP_USER));
        if ($username === '') {
            $username = trim((string) Configuration::get(static::CONFIG_SMTP_USER));
        }

        // A blank password field means "keep the saved password", so test that one.
        $password = (string) Tools::getValue(static::CONFIG_SMTP_PASSWORD);
        if ($password === '') {
            $password = (string) Configuration::get(static::CONFIG_SMTP_PASSWORD);
        }

        if ($host === '' || $username === '' || $password === '') {
            $this->ajaxResponse([
                'success' => false,
                'message' => $this->l('Enter the SMTP host, username and password (or save them first), then test the connection.'),
            ]);
        }

        $mailer = new MessageGlobeSmtpMailer([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
        ]);

        // Give the mailer a sender so its EHLO hostname is derived sensibly.
        $from = trim((string) Configuration::get(static::CONFIG_SMTP_FROM));
        if ($from === '') {
            $from = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        if ($from !== '') {
            $mailer->setFrom($from);
        }

        try {
            $mailer->verifyConnection();
        } catch (Exception $exception) {
            $this->ajaxResponse([
                'success' => false,
                'message' => sprintf($this->l('SMTP test failed: %s'), $exception->getMessage()),
            ]);
        }

        $this->ajaxResponse([
            'success' => true,
            'message' => $this->l('Success: connected and authenticated with the SMTP server.'),
        ]);
    }

    protected function renderCronLogs()
    {
        $logs = $this->getCronLogs(10);

        if (!$logs) {
            return '<h4>' . $this->l('Recent cron runs') . '</h4><p class="help-block">' . $this->l('No cron runs logged yet.') . '</p>';
        }

        $html = '<h4>' . $this->l('Recent cron runs') . '</h4>';
        $html .= '<div class="table-responsive"><table class="table"><thead><tr>';
        $html .= '<th>' . $this->l('Date') . '</th>';
        $html .= '<th>' . $this->l('Processed') . '</th>';
        $html .= '<th>' . $this->l('Synced') . '</th>';
        $html .= '<th>' . $this->l('Failed') . '</th>';
        $html .= '<th>' . $this->l('Retried') . '</th>';
        $html .= '<th>' . $this->l('Remaining') . '</th>';
        $html .= '<th>' . $this->l('Message') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . (int) $log['processed'] . '</td>';
            $html .= '<td>' . (int) $log['synced'] . '</td>';
            $html .= '<td>' . (int) $log['failed'] . '</td>';
            $html .= '<td>' . (int) $log['retried'] . '</td>';
            $html .= '<td>' . (int) $log['remaining'] . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $log['message'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    protected function getModuleConfigUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
    }

    protected function getCronUrl()
    {
        $cronToken = (string) Configuration::get(static::CONFIG_CRON_TOKEN);
        if ($cronToken === '') {
            $cronToken = $this->generateCronToken();
            Configuration::updateValue(static::CONFIG_CRON_TOKEN, $cronToken);
        }

        return $this->context->link->getModuleLink($this->name, 'cron', [
            'token' => $cronToken,
        ]);
    }

    protected function generateCronToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }

        return sha1(uniqid((string) mt_rand(), true));
    }

    protected function handleCustomerHook(array $params)
    {
        if (empty($params['object']) || !Validate::isLoadedObject($params['object'])) {
            return;
        }

        $this->enqueueCustomer((int) $params['object']->id);
    }

    protected function syncCustomerById($idCustomer)
    {
        $customer = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            return false;
        }

        if (!$this->isConfigured()) {
            return false;
        }

        $email = trim((string) $customer->email);
        $phone = $this->getCustomerPhone((int) $customer->id);
        $firstName = trim((string) $customer->firstname);
        $lastName = trim((string) $customer->lastname);

        if ($email === '' && $phone === '') {
            $this->removeRemoteContactByCustomerId((int) $customer->id);

            return true;
        }

        $existing = $this->getMappingByCustomerId((int) $customer->id);
        $groupId = $this->getGroupId();

        try {
            $client = $this->getContactsClient();

            if (!empty($existing['remote_uid'])) {
                $client->delete($groupId, (string) $existing['remote_uid']);
            }

            $contact = $client->create(
                $groupId,
                $this->buildContactRequest($email, $phone, $firstName, $lastName)
            );

            $remoteUid = $contact->uid();
            if ($remoteUid === '') {
                throw new Exception('Missing contact UID in Message Globe response.');
            }

            $this->upsertMapping((int) $customer->id, $remoteUid, $email, $phone);

            return true;
        } catch (Exception $exception) {
            PrestaShopLogger::addLog(
                sprintf('Message Globe sync failed for customer %d: %s', (int) $customer->id, $exception->getMessage()),
                3,
                null,
                'Customer',
                (int) $customer->id,
                true
            );

            return false;
        }
    }

    protected function syncAllCustomers()
    {
        $query = new DbQuery();
        $query->select('id_customer');
        $query->from('customer');
        $query->orderBy('id_customer ASC');

        $rows = Db::getInstance()->executeS($query);
        $result = [
            'processed' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        if (!is_array($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            $idCustomer = isset($row['id_customer']) ? (int) $row['id_customer'] : 0;
            if ($idCustomer <= 0) {
                continue;
            }

            ++$result['processed'];

            try {
                if ($this->syncCustomerById($idCustomer)) {
                    ++$result['synced'];
                } else {
                    ++$result['failed'];
                }
            } catch (Exception $exception) {
                ++$result['failed'];

                PrestaShopLogger::addLog(
                    sprintf('Message Globe bulk sync failed for customer %d: %s', $idCustomer, $exception->getMessage()),
                    3,
                    null,
                    'Customer',
                    $idCustomer,
                    true
                );
            }
        }

        return $result;
    }

    protected function syncCustomerBatch($offset, $limit)
    {
        $query = new DbQuery();
        $query->select('id_customer');
        $query->from('customer');
        $query->orderBy('id_customer ASC');
        $query->limit((int) $limit, (int) $offset);

        $rows = Db::getInstance()->executeS($query);
        $result = [
            'processed' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        if (!is_array($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            $idCustomer = isset($row['id_customer']) ? (int) $row['id_customer'] : 0;
            if ($idCustomer <= 0) {
                continue;
            }

            ++$result['processed'];

            if ($this->syncCustomerById($idCustomer)) {
                ++$result['synced'];
            } else {
                ++$result['failed'];
            }
        }

        return $result;
    }

    protected function getCustomerCount()
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('customer');

        return (int) Db::getInstance()->getValue($query);
    }

    public function runCron($token, $limit = 25)
    {
        $base = [
            'success' => true,
            'message' => '',
            'processed' => 0,
            'synced' => 0,
            'failed' => 0,
            'retried' => 0,
            'remaining' => 0,
            'sms_processed' => 0,
            'sms_sent' => 0,
            'sms_failed' => 0,
            'sms_retried' => 0,
        ];

        $configuredToken = (string) Configuration::get(static::CONFIG_CRON_TOKEN);
        if ($configuredToken === '' || !hash_equals($configuredToken, (string) $token)) {
            $result = array_merge($base, ['success' => false, 'message' => 'Invalid cron token.']);
            $this->logCronRun($result);

            return $result;
        }

        // Contact sync needs the token + group id; SMS needs only the token.
        if (!$this->isConfigured() && !$this->hasAccessToken()) {
            $result = array_merge($base, ['success' => false, 'message' => 'Module is not configured.']);
            $this->logCronRun($result);

            return $result;
        }

        $limit = max(1, min(100, (int) $limit));
        $result = $base;

        if ($this->isConfigured()) {
            $result = array_merge($result, $this->processQueueBatch($limit));
        }

        if ($this->hasAccessToken()) {
            $result = array_merge($result, $this->processSmsQueueBatch($limit));
        }

        $result['success'] = true;
        $result['message'] = sprintf(
            'Processed %d sync, %d SMS.',
            (int) $result['processed'],
            (int) $result['sms_processed']
        );

        $this->logCronRun($result);

        return $result;
    }

    protected function queueAllCustomers()
    {
        $query = new DbQuery();
        $query->select('id_customer');
        $query->from('customer');

        $rows = Db::getInstance()->executeS($query);
        if (!is_array($rows)) {
            return 0;
        }

        $queued = 0;
        foreach ($rows as $row) {
            $idCustomer = isset($row['id_customer']) ? (int) $row['id_customer'] : 0;
            if ($idCustomer <= 0) {
                continue;
            }

            if ($this->enqueueCustomer($idCustomer)) {
                ++$queued;
            }
        }

        return $queued;
    }

    protected function enqueueCustomer($idCustomer)
    {
        $idCustomer = (int) $idCustomer;
        if ($idCustomer <= 0) {
            return false;
        }

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . static::TABLE_SYNC_QUEUE;
        $existingJob = $db->getRow('SELECT * FROM `' . bqSQL($table) . '` WHERE `id_customer` = ' . $idCustomer . ' AND `status` IN ("pending", "processing", "failed") ORDER BY `id_messageglobe_sync_queue` DESC');

        if (is_array($existingJob) && !empty($existingJob['id_messageglobe_sync_queue'])) {
            return (bool) $db->update(static::TABLE_SYNC_QUEUE, [
                'status' => 'pending',
                'next_attempt_at' => pSQL(date('Y-m-d H:i:s')),
                'last_error' => '',
                'updated_at' => pSQL(date('Y-m-d H:i:s')),
            ], 'id_messageglobe_sync_queue = ' . (int) $existingJob['id_messageglobe_sync_queue']);
        }

        return (bool) $db->insert(static::TABLE_SYNC_QUEUE, [
            'id_customer' => $idCustomer,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => pSQL(date('Y-m-d H:i:s')),
            'last_error' => '',
            'created_at' => pSQL(date('Y-m-d H:i:s')),
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        ]);
    }

    protected function clearQueue()
    {
        Db::getInstance()->delete(static::TABLE_SYNC_QUEUE, '1=1');
    }

    protected function getQueueStats()
    {
        $rows = Db::getInstance()->executeS('SELECT `status`, COUNT(*) AS total FROM `' . _DB_PREFIX_ . static::TABLE_SYNC_QUEUE . '` GROUP BY `status`');
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'done' => 0,
            'failed' => 0,
            'retry_waiting' => 0,
        ];

        if (!is_array($rows)) {
            return $stats;
        }

        foreach ($rows as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = (int) $row['total'];
            }
        }

        return $stats;
    }

    protected function processQueueBatch($limit)
    {
        $this->releaseStaleProcessingJobs();
        $jobs = $this->claimQueueJobs((int) $limit);
        $result = [
            'success' => true,
            'message' => 'Queue processed.',
            'processed' => 0,
            'synced' => 0,
            'failed' => 0,
            'retried' => 0,
            'remaining' => (int) $this->getQueueStats()['pending'],
        ];

        foreach ($jobs as $job) {
            ++$result['processed'];
            $jobId = (int) $job['id_messageglobe_sync_queue'];
            $idCustomer = (int) $job['id_customer'];

            if ($this->syncCustomerById($idCustomer)) {
                ++$result['synced'];
                $this->markQueueJobDone($jobId);
            } else {
                ++$result['failed'];
                if ($this->markQueueJobRetryOrFailed($job, 'Sync failed, see PrestaShop logs for details.')) {
                    ++$result['retried'];
                }
            }
        }

        $result['remaining'] = (int) $this->getQueueStats()['pending'];
        $result['message'] = sprintf('Processed %d queued customers.', (int) $result['processed']);

        return $result;
    }

    protected function claimQueueJobs($limit)
    {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('*');
        $query->from(static::TABLE_SYNC_QUEUE);
        $query->where('status = "pending"');
        $query->where('(next_attempt_at IS NULL OR next_attempt_at <= NOW())');
        $query->orderBy('id_messageglobe_sync_queue ASC');
        $query->limit((int) $limit);

        $jobs = $db->executeS($query);
        if (!is_array($jobs)) {
            return [];
        }

        $claimed = [];
        foreach ($jobs as $job) {
            $jobId = (int) $job['id_messageglobe_sync_queue'];
            $updated = $db->update(static::TABLE_SYNC_QUEUE, [
                'status' => 'processing',
                'attempts' => (int) $job['attempts'] + 1,
                'updated_at' => pSQL(date('Y-m-d H:i:s')),
            ], 'id_messageglobe_sync_queue = ' . $jobId . ' AND status = "pending"');

            if ($updated) {
                $claimed[] = $job;
            }
        }

        return $claimed;
    }

    protected function markQueueJobDone($jobId)
    {
        Db::getInstance()->update(static::TABLE_SYNC_QUEUE, [
            'status' => 'done',
            'last_error' => '',
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        ], 'id_messageglobe_sync_queue = ' . (int) $jobId);
    }

    protected function markQueueJobFailed($jobId, $message)
    {
        Db::getInstance()->update(static::TABLE_SYNC_QUEUE, [
            'status' => 'failed',
            'last_error' => pSQL((string) $message),
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        ], 'id_messageglobe_sync_queue = ' . (int) $jobId);
    }

    protected function markQueueJobRetryOrFailed(array $job, $message)
    {
        $attempts = isset($job['attempts']) ? (int) $job['attempts'] : 0;
        $jobId = (int) $job['id_messageglobe_sync_queue'];

        if ($attempts >= static::MAX_QUEUE_ATTEMPTS) {
            $this->markQueueJobFailed($jobId, $message);

            return false;
        }

        $delayMinutes = min(60, (int) pow(2, max(0, $attempts - 1)) * 5);

        Db::getInstance()->update(static::TABLE_SYNC_QUEUE, [
            'status' => 'pending',
            'last_error' => pSQL((string) $message),
            'next_attempt_at' => pSQL(date('Y-m-d H:i:s', time() + ($delayMinutes * 60))),
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        ], 'id_messageglobe_sync_queue = ' . $jobId);

        return true;
    }

    protected function releaseStaleProcessingJobs()
    {
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . static::TABLE_SYNC_QUEUE . '` SET `status` = "pending", `next_attempt_at` = NOW(), `updated_at` = NOW() WHERE `status` = "processing" AND `updated_at` < DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    }

    protected function getCronLogs($limit)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from(static::TABLE_CRON_LOG);
        $query->orderBy('id_messageglobe_cron_log DESC');
        $query->limit((int) $limit);

        $rows = Db::getInstance()->executeS($query);

        return is_array($rows) ? $rows : [];
    }

    protected function logCronRun(array $result)
    {
        Db::getInstance()->insert(static::TABLE_CRON_LOG, [
            'success' => !empty($result['success']) ? 1 : 0,
            'processed' => isset($result['processed']) ? (int) $result['processed'] : 0,
            'synced' => isset($result['synced']) ? (int) $result['synced'] : 0,
            'failed' => isset($result['failed']) ? (int) $result['failed'] : 0,
            'retried' => isset($result['retried']) ? (int) $result['retried'] : 0,
            'remaining' => isset($result['remaining']) ? (int) $result['remaining'] : 0,
            'message' => pSQL(isset($result['message']) ? (string) $result['message'] : ''),
            'created_at' => pSQL(date('Y-m-d H:i:s')),
        ]);

        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . static::TABLE_CRON_LOG . '` WHERE `id_messageglobe_cron_log` NOT IN (SELECT `id_messageglobe_cron_log` FROM (SELECT `id_messageglobe_cron_log` FROM `' . _DB_PREFIX_ . static::TABLE_CRON_LOG . '` ORDER BY `id_messageglobe_cron_log` DESC LIMIT 50) AS recent_logs)');
    }

    protected function removeRemoteContactByCustomerId($idCustomer)
    {
        $mapping = $this->getMappingByCustomerId((int) $idCustomer);
        if (empty($mapping['remote_uid'])) {
            $this->deleteMapping((int) $idCustomer);

            return;
        }

        if ($this->isConfigured()) {
            try {
                $this->getContactsClient()->delete($this->getGroupId(), (string) $mapping['remote_uid']);
            } catch (Exception $exception) {
                PrestaShopLogger::addLog(
                    sprintf('Message Globe delete failed for customer %d: %s', (int) $idCustomer, $exception->getMessage()),
                    3,
                    null,
                    'Customer',
                    (int) $idCustomer,
                    true
                );
            }
        }

        $this->deleteMapping((int) $idCustomer);
    }

    protected function getCustomerPhone($idCustomer)
    {
        $query = new DbQuery();
        $query->select('phone_mobile, phone');
        $query->from('address');
        $query->where('id_customer = ' . (int) $idCustomer);
        $query->where('deleted = 0');
        $query->orderBy('id_address DESC');

        $addresses = Db::getInstance()->executeS($query);
        if (!is_array($addresses)) {
            return '';
        }

        foreach ($addresses as $address) {
            foreach (['phone_mobile', 'phone'] as $field) {
                if (!empty($address[$field])) {
                    return $this->normalizePhone((string) $address[$field]);
                }
            }
        }

        return '';
    }

    protected function normalizePhone($phone)
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $hasPlusPrefix = strpos($phone, '+') === 0;
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === null || $digits === '') {
            return '';
        }

        return $hasPlusPrefix ? '+' . $digits : $digits;
    }

    /**
     * Build a Contacts REST client from the bundled MessageGlobe PHP SDK.
     *
     * Uses the SDK's zero-dependency cURL transport with the same snappy
     * timeouts the legacy client used (5s connect / 15s total), so contact
     * sync never stalls a back-office or checkout request for long.
     *
     * @return \MessageGlobe\Contacts\ContactsClient
     *
     * @throws \MessageGlobe\Exception\ConfigurationException When the token is empty.
     */
    protected function getContactsClient()
    {
        return new \MessageGlobe\Contacts\ContactsClient($this->getApiConfig(), $this->getHttpClient());
    }

    protected function getGroupId()
    {
        return trim((string) Configuration::get(static::CONFIG_GROUP_ID));
    }

    /**
     * Build an SDK ContactRequest, guarding each identifier independently so a
     * malformed phone never blocks an otherwise-valid email (and vice versa).
     *
     * @return \MessageGlobe\Contacts\ContactRequest
     */
    protected function buildContactRequest($email, $phone, $firstName, $lastName)
    {
        $request = new \MessageGlobe\Contacts\ContactRequest();

        if ($phone !== '') {
            try {
                $request->phone($phone);
            } catch (\MessageGlobe\Exception\ValidationException $exception) {
                // Skip a malformed phone; fall back to email-only when possible.
            }
        }

        if ($email !== '') {
            try {
                $request->email($email);
            } catch (\MessageGlobe\Exception\ValidationException $exception) {
                // Skip a malformed email.
            }
        }

        if ($firstName !== '') {
            $request->firstName($firstName);
        }

        if ($lastName !== '') {
            $request->lastName($lastName);
        }

        return $request;
    }

    protected function isConfigured()
    {
        return (bool) Configuration::get(static::CONFIG_ACCESS_TOKEN) && (bool) Configuration::get(static::CONFIG_GROUP_ID);
    }

    protected function getMappingByCustomerId($idCustomer)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from(static::TABLE_CONTACT_MAP);
        $query->where('id_customer = ' . (int) $idCustomer);

        $row = Db::getInstance()->getRow($query);

        return is_array($row) ? $row : [];
    }

    protected function upsertMapping($idCustomer, $remoteUid, $email, $phone)
    {
        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $data = [
            'id_customer' => (int) $idCustomer,
            'remote_uid' => pSQL($remoteUid),
            'email' => pSQL($email),
            'phone' => pSQL($phone),
            'updated_at' => pSQL($now),
        ];

        if ($this->getMappingByCustomerId((int) $idCustomer)) {
            $db->update(static::TABLE_CONTACT_MAP, $data, 'id_customer = ' . (int) $idCustomer);

            return;
        }

        $data['created_at'] = pSQL($now);
        $db->insert(static::TABLE_CONTACT_MAP, $data);
    }

    protected function deleteMapping($idCustomer)
    {
        Db::getInstance()->delete(static::TABLE_CONTACT_MAP, 'id_customer = ' . (int) $idCustomer);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SMS: order hooks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * New-order admin alert. Fires when an order is validated (checkout).
     */
    public function hookActionValidateOrder(array $params)
    {
        if (!$this->isSmsReady() || (int) Configuration::get(static::CONFIG_SMS_ADMIN_ENABLED) !== 1) {
            return;
        }

        $recipients = trim((string) Configuration::get(static::CONFIG_SMS_ADMIN_RECIPIENTS));
        if ($recipients === '') {
            return;
        }

        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }

        $order = $params['order'];
        $orderState = isset($params['orderStatus']) ? $params['orderStatus'] : null;

        $template = trim((string) Configuration::get(static::CONFIG_SMS_ADMIN_TEMPLATE));
        if ($template === '') {
            $template = static::DEFAULT_SMS_ADMIN_TEMPLATE;
        }

        $message = $this->renderSmsTemplate($template, $this->buildOrderVars($order, $orderState));
        if ($message !== '') {
            $this->enqueueSms($recipients, $message);
        }
    }

    /**
     * Customer order-status SMS. Fires after any order status change (including
     * the initial state set at order creation).
     */
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        if (!$this->isSmsReady() || (int) Configuration::get(static::CONFIG_SMS_CUSTOMER_ENABLED) !== 1) {
            return;
        }

        $newState = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (!Validate::isLoadedObject($newState) || $idOrder <= 0) {
            return;
        }

        $map = $this->getSmsStateMap();
        $idState = (int) $newState->id;
        if (empty($map[$idState]) || (int) $map[$idState]['enabled'] !== 1) {
            return;
        }

        $template = isset($map[$idState]['template']) ? trim((string) $map[$idState]['template']) : '';
        if ($template === '') {
            return;
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $phone = $this->resolveOrderPhone($order);
        if ($phone === '') {
            return;
        }

        $message = $this->renderSmsTemplate($template, $this->buildOrderVars($order, $newState));
        if ($message !== '') {
            $this->enqueueSms($phone, $message);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // SMS: helpers
    // ─────────────────────────────────────────────────────────────────────

    protected function hasAccessToken()
    {
        return trim((string) Configuration::get(static::CONFIG_ACCESS_TOKEN)) !== '';
    }

    protected function isSmsReady()
    {
        return (int) Configuration::get(static::CONFIG_SMS_ENABLED) === 1 && $this->hasAccessToken();
    }

    protected function getSmsClient()
    {
        return new \MessageGlobe\Sms\SmsClient($this->getApiConfig(), $this->getHttpClient());
    }

    protected function getSendersClient()
    {
        return new \MessageGlobe\Senders\SendersClient($this->getApiConfig(), $this->getHttpClient());
    }

    protected function getApiConfig()
    {
        return new \MessageGlobe\ApiConfig(
            trim((string) Configuration::get(static::CONFIG_ACCESS_TOKEN)),
            \MessageGlobe\ApiConfig::DEFAULT_BASE_URL,
            \MessageGlobe\ApiConfig::LANGUAGE_IT
        );
    }

    protected function getHttpClient()
    {
        return new \MessageGlobe\Http\CurlHttpClient(15, 5, 'messageglobesync/' . $this->version);
    }

    /**
     * Approved sender IDs on the account, or [] when the API is unreachable.
     *
     * @return array<int,string>
     */
    protected function getSenderOptions()
    {
        if (!$this->hasAccessToken()) {
            return [];
        }

        try {
            $senders = $this->getSendersClient()->all();
        } catch (Exception $exception) {
            return [];
        }

        $options = [];
        foreach ($senders as $sender) {
            $id = (string) $sender->senderId();
            if ($id === '') {
                continue;
            }
            $status = strtolower((string) $sender->status());
            if ($status !== '' && $status !== 'active') {
                continue;
            }
            $options[$id] = $id;
        }

        return array_values($options);
    }

    protected function renderSmsTemplate($template, array $vars)
    {
        return trim(str_replace(array_keys($vars), array_values($vars), (string) $template));
    }

    /**
     * @return array<string,string>
     */
    protected function buildOrderVars($order, $orderState = null)
    {
        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency((int) $order->id_currency);

        $total = number_format((float) $order->total_paid, 2);
        try {
            $total = (string) Tools::displayPrice((float) $order->total_paid, $currency);
        } catch (\Throwable $exception) {
            if (Validate::isLoadedObject($currency)) {
                $total = number_format((float) $order->total_paid, 2) . ' ' . (string) $currency->iso_code;
            }
        }

        $tracking = '';
        $carrierName = '';
        try {
            $idOrderCarrier = (int) $order->getIdOrderCarrier();
            if ($idOrderCarrier > 0) {
                $orderCarrier = new OrderCarrier($idOrderCarrier);
                if (Validate::isLoadedObject($orderCarrier)) {
                    $tracking = (string) $orderCarrier->tracking_number;
                }
            }
            if ((int) $order->id_carrier > 0) {
                $carrier = new Carrier((int) $order->id_carrier, (int) $this->context->language->id);
                if (Validate::isLoadedObject($carrier)) {
                    $carrierName = (string) $carrier->name;
                }
            }
        } catch (\Throwable $exception) {
            // Tracking/carrier are best-effort; leave them blank on any failure.
        }

        return [
            '{order_reference}' => (string) $order->reference,
            '{order_id}' => (string) $order->id,
            '{firstname}' => Validate::isLoadedObject($customer) ? (string) $customer->firstname : '',
            '{lastname}' => Validate::isLoadedObject($customer) ? (string) $customer->lastname : '',
            '{total_paid}' => $total,
            '{order_state}' => $this->orderStateName($orderState),
            '{payment}' => (string) $order->payment,
            '{tracking_number}' => $tracking,
            '{carrier}' => $carrierName,
            '{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'),
        ];
    }

    protected function orderStateName($orderState)
    {
        if ($orderState === null || !Validate::isLoadedObject($orderState)) {
            return '';
        }

        if (is_array($orderState->name)) {
            $idLang = (int) $this->context->language->id;
            if (isset($orderState->name[$idLang])) {
                return (string) $orderState->name[$idLang];
            }
            $first = reset($orderState->name);

            return $first === false ? '' : (string) $first;
        }

        return (string) $orderState->name;
    }

    protected function resolveOrderPhone($order)
    {
        foreach ([(int) $order->id_address_delivery, (int) $order->id_address_invoice] as $idAddress) {
            if ($idAddress <= 0) {
                continue;
            }
            $address = new Address($idAddress);
            if (!Validate::isLoadedObject($address)) {
                continue;
            }
            foreach (['phone_mobile', 'phone'] as $field) {
                if (!empty($address->$field)) {
                    return $this->normalizePhone((string) $address->$field);
                }
            }
        }

        return $this->getCustomerPhone((int) $order->id_customer);
    }

    /**
     * @return array<int,array{enabled:int,template:string}>
     */
    protected function getSmsStateMap()
    {
        $decoded = json_decode((string) Configuration::get(static::CONFIG_SMS_STATE_MAP), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function saveSmsStateMap()
    {
        $map = [];
        foreach ($this->getOrderStates() as $state) {
            $id = (int) $state['id_order_state'];
            $enabled = (int) Tools::getValue('sms_state_enabled_' . $id);
            $template = (string) Tools::getValue('sms_state_tpl_' . $id);

            if ($enabled === 1 || trim($template) !== '') {
                $map[$id] = ['enabled' => $enabled, 'template' => $template];
            }
        }

        Configuration::updateValue(static::CONFIG_SMS_STATE_MAP, json_encode($map));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function getOrderStates()
    {
        $states = OrderState::getOrderStates((int) $this->context->language->id);

        return is_array($states) ? $states : [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // SMS: async queue
    // ─────────────────────────────────────────────────────────────────────

    protected function enqueueSms($recipient, $message)
    {
        $recipient = trim((string) $recipient);
        $message = (string) $message;
        if ($recipient === '' || trim($message) === '') {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->insert(static::TABLE_SMS_QUEUE, [
            'recipient' => pSQL($recipient),
            'message' => pSQL($message),
            'sender_id' => pSQL((string) Configuration::get(static::CONFIG_SMS_SENDER_ID)),
            'gateway' => pSQL((string) Configuration::get(static::CONFIG_SMS_GATEWAY)),
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => pSQL($now),
            'last_error' => '',
            'created_at' => pSQL($now),
            'updated_at' => pSQL($now),
        ]);
    }

    /**
     * @return array{sms_processed:int,sms_sent:int,sms_failed:int,sms_retried:int}
     */
    protected function processSmsQueueBatch($limit)
    {
        $this->releaseStaleSmsJobs();
        $jobs = $this->claimSmsJobs((int) $limit);
        $result = ['sms_processed' => 0, 'sms_sent' => 0, 'sms_failed' => 0, 'sms_retried' => 0];

        foreach ($jobs as $job) {
            ++$result['sms_processed'];
            $jobId = (int) $job['id_messageglobe_sms_queue'];

            try {
                $this->sendQueuedSms($job);
                ++$result['sms_sent'];
                $this->markSmsJobDone($jobId);
            } catch (Exception $exception) {
                ++$result['sms_failed'];
                $this->logSms('failed', (string) $job['recipient'], (string) $job['message'], ['error' => $exception->getMessage()]);
                if ($this->markSmsJobRetryOrFailed($job, $exception->getMessage())) {
                    ++$result['sms_retried'];
                }
            }
        }

        return $result;
    }

    protected function sendQueuedSms(array $job)
    {
        $message = $this->buildSmsMessage(
            (string) $job['recipient'],
            (string) $job['message'],
            (string) $job['gateway'],
            (string) $job['sender_id']
        );

        $result = $this->getSmsClient()->send($message)->first();

        $this->logSms('sent', (string) $job['recipient'], (string) $job['message'], [
            'message_id' => $result->messageId(),
            'cost' => $result->cost(),
        ]);

        return $result;
    }

    protected function buildSmsMessage($recipient, $body, $gateway = '', $senderId = '')
    {
        $message = new \MessageGlobe\Sms\SmsMessage();
        $message->to((string) $recipient);

        $gateway = (string) $gateway !== '' ? (string) $gateway : (string) Configuration::get(static::CONFIG_SMS_GATEWAY);
        if ($gateway === 'LQ') {
            $message->lowQuality();
        } else {
            $message->highQuality();
            $senderId = (string) $senderId !== '' ? (string) $senderId : (string) Configuration::get(static::CONFIG_SMS_SENDER_ID);
            if (trim($senderId) !== '') {
                $message->from($senderId);
            }
        }

        $message->messageAutoType((string) $body);

        return $message;
    }

    protected function claimSmsJobs($limit)
    {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('*');
        $query->from(static::TABLE_SMS_QUEUE);
        $query->where('status = "pending"');
        $query->where('(next_attempt_at IS NULL OR next_attempt_at <= NOW())');
        $query->orderBy('id_messageglobe_sms_queue ASC');
        $query->limit((int) $limit);

        $jobs = $db->executeS($query);
        if (!is_array($jobs)) {
            return [];
        }

        $claimed = [];
        foreach ($jobs as $job) {
            $jobId = (int) $job['id_messageglobe_sms_queue'];
            $updated = $db->update(static::TABLE_SMS_QUEUE, [
                'status' => 'processing',
                'attempts' => (int) $job['attempts'] + 1,
                'updated_at' => pSQL(date('Y-m-d H:i:s')),
            ], 'id_messageglobe_sms_queue = ' . $jobId . ' AND status = "pending"');

            if ($updated) {
                $claimed[] = $job;
            }
        }

        return $claimed;
    }

    protected function markSmsJobDone($jobId)
    {
        Db::getInstance()->update(static::TABLE_SMS_QUEUE, [
            'status' => 'done',
            'last_error' => '',
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        ], 'id_messageglobe_sms_queue = ' . (int) $jobId);
    }

    protected function markSmsJobRetryOrFailed(array $job, $message)
    {
        $attempts = isset($job['attempts']) ? (int) $job['attempts'] : 0;
        $jobId = (int) $job['id_messageglobe_sms_queue'];

        if ($attempts >= static::MAX_SMS_ATTEMPTS) {
            Db::getInstance()->update(static::TABLE_SMS_QUEUE, [
                'status' => 'failed',
                'last_error' => pSQL((string) $message),
                'updated_at' => pSQL(date('Y-m-d H:i:s')),
            ], 'id_messageglobe_sms_queue = ' . $jobId);

            return false;
        }

        $delayMinutes = min(60, (int) pow(2, max(0, $attempts - 1)) * 5);

        Db::getInstance()->update(static::TABLE_SMS_QUEUE, [
            'status' => 'pending',
            'last_error' => pSQL((string) $message),
            'next_attempt_at' => pSQL(date('Y-m-d H:i:s', time() + ($delayMinutes * 60))),
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        ], 'id_messageglobe_sms_queue = ' . $jobId);

        return true;
    }

    protected function releaseStaleSmsJobs()
    {
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . static::TABLE_SMS_QUEUE . '` SET `status` = "pending", `next_attempt_at` = NOW(), `updated_at` = NOW() WHERE `status` = "processing" AND `updated_at` < DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    }

    protected function logSms($status, $recipient, $message, array $context = [])
    {
        $db = Db::getInstance();
        $db->insert(static::TABLE_SMS_LOG, [
            'recipient' => pSQL(Tools::substr((string) $recipient, 0, 190)),
            'message' => pSQL((string) $message),
            'status' => pSQL((string) $status),
            'message_id' => pSQL((string) (isset($context['message_id']) ? $context['message_id'] : '')),
            'cost' => (isset($context['cost']) && $context['cost'] !== null) ? (float) $context['cost'] : 0,
            'error' => pSQL((string) (isset($context['error']) ? $context['error'] : '')),
            'created_at' => pSQL(date('Y-m-d H:i:s')),
        ]);

        // Keep only the most recent 500 rows.
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . static::TABLE_SMS_LOG . '` WHERE `id_messageglobe_sms_log` NOT IN (SELECT id FROM (SELECT `id_messageglobe_sms_log` AS id FROM `' . _DB_PREFIX_ . static::TABLE_SMS_LOG . '` ORDER BY `id_messageglobe_sms_log` DESC LIMIT 500) AS recent)');
    }

    protected function getSmsLogs($limit)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from(static::TABLE_SMS_LOG);
        $query->orderBy('id_messageglobe_sms_log DESC');
        $query->limit((int) $limit);

        $rows = Db::getInstance()->executeS($query);

        return is_array($rows) ? $rows : [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // SMS: admin AJAX (send test)
    // ─────────────────────────────────────────────────────────────────────

    protected function ajaxTestSms()
    {
        if (!$this->hasAccessToken()) {
            $this->ajaxResponse(['success' => false, 'message' => $this->l('Set the Message Globe access token first.')]);
        }

        $to = trim((string) Tools::getValue('to'));
        $text = (string) Tools::getValue('message');
        if ($to === '' || trim($text) === '') {
            $this->ajaxResponse(['success' => false, 'message' => $this->l('Enter both a phone number and a message.')]);
        }

        try {
            $result = $this->getSmsClient()->send($this->buildSmsMessage($to, $text))->first();
            $this->logSms('sent', $to, $text, ['message_id' => $result->messageId(), 'cost' => $result->cost()]);

            $this->ajaxResponse([
                'success' => true,
                'message' => sprintf($this->l('Test SMS accepted. Message ID: %s'), $result->messageId()),
            ]);
        } catch (Exception $exception) {
            $this->logSms('failed', $to, $text, ['error' => $exception->getMessage()]);
            $this->ajaxResponse([
                'success' => false,
                'message' => sprintf($this->l('SMS test failed: %s'), $exception->getMessage()),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // SMS: admin UI
    // ─────────────────────────────────────────────────────────────────────

    protected function renderSmsForm()
    {
        $senderInput = [
            'type' => 'text',
            'label' => $this->l('Default sender ID'),
            'name' => static::CONFIG_SMS_SENDER_ID,
            'desc' => $this->l('The sender shown to recipients (required for the HQ gateway; max 11 chars if alphanumeric).'),
        ];

        $senderOptions = $this->getSenderOptions();
        if (!empty($senderOptions)) {
            $query = [['id' => '', 'name' => $this->l('— None —')]];
            foreach ($senderOptions as $sid) {
                $query[] = ['id' => $sid, 'name' => $sid];
            }
            $senderInput = [
                'type' => 'select',
                'label' => $this->l('Default sender ID'),
                'name' => static::CONFIG_SMS_SENDER_ID,
                'options' => ['query' => $query, 'id' => 'id', 'name' => 'name'],
                'desc' => $this->l('Approved sender IDs on your account (used with the HQ gateway).'),
            ];
        }

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('SMS notifications'), 'icon' => 'icon-mobile'],
                'description' => $this->l('Send transactional SMS through Message Globe. Uses the same access token as contact sync.'),
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable SMS'),
                        'name' => static::CONFIG_SMS_ENABLED,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'sms_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'sms_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    $senderInput,
                    [
                        'type' => 'select',
                        'label' => $this->l('Gateway'),
                        'name' => static::CONFIG_SMS_GATEWAY,
                        'options' => [
                            'query' => [
                                ['id' => 'HQ', 'name' => $this->l('High quality (custom sender + delivery reports)')],
                                ['id' => 'LQ', 'name' => $this->l('Low quality (shared numbers)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Customer order notifications'),
                        'name' => static::CONFIG_SMS_CUSTOMER_ENABLED,
                        'is_bool' => true,
                        'desc' => $this->l('Text customers when their order reaches a status configured in the panel below.'),
                        'values' => [
                            ['id' => 'sms_cust_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'sms_cust_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Admin new-order alert'),
                        'name' => static::CONFIG_SMS_ADMIN_ENABLED,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'sms_admin_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'sms_admin_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Alert recipients'),
                        'name' => static::CONFIG_SMS_ADMIN_RECIPIENTS,
                        'desc' => $this->l('Comma-separated staff phone numbers in international format.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Admin alert template'),
                        'name' => static::CONFIG_SMS_ADMIN_TEMPLATE,
                        'rows' => 2,
                        'cols' => 60,
                        'desc' => $this->l('Tags: {order_reference} {order_id} {firstname} {lastname} {total_paid} {payment} {shop_name}'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save SMS settings')],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMessageGlobeSmsConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                static::CONFIG_SMS_ENABLED => (int) Configuration::get(static::CONFIG_SMS_ENABLED),
                static::CONFIG_SMS_SENDER_ID => Configuration::get(static::CONFIG_SMS_SENDER_ID),
                static::CONFIG_SMS_GATEWAY => Configuration::get(static::CONFIG_SMS_GATEWAY),
                static::CONFIG_SMS_CUSTOMER_ENABLED => (int) Configuration::get(static::CONFIG_SMS_CUSTOMER_ENABLED),
                static::CONFIG_SMS_ADMIN_ENABLED => (int) Configuration::get(static::CONFIG_SMS_ADMIN_ENABLED),
                static::CONFIG_SMS_ADMIN_RECIPIENTS => Configuration::get(static::CONFIG_SMS_ADMIN_RECIPIENTS),
                static::CONFIG_SMS_ADMIN_TEMPLATE => Configuration::get(static::CONFIG_SMS_ADMIN_TEMPLATE),
            ],
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    protected function renderSmsStatePanel()
    {
        $states = $this->getOrderStates();
        $map = $this->getSmsStateMap();
        $action = htmlspecialchars($this->getModuleConfigUrl(), ENT_QUOTES, 'UTF-8');

        $html = '<div class="panel"><h3><i class="icon-list"></i> ' . $this->l('Order-status SMS templates') . '</h3>';
        $html .= '<p class="help-block">' . $this->l('Choose which order statuses text the customer, and the message for each.') . '</p>';
        $html .= '<p class="help-block">' . $this->l('Tags:') . ' <code>{order_reference}</code> <code>{order_id}</code> <code>{firstname}</code> <code>{lastname}</code> <code>{total_paid}</code> <code>{order_state}</code> <code>{payment}</code> <code>{tracking_number}</code> <code>{carrier}</code> <code>{shop_name}</code></p>';
        $html .= '<form method="post" action="' . $action . '">';
        $html .= '<div class="table-responsive"><table class="table"><thead><tr><th style="width:60px; text-align:center;">' . $this->l('Send') . '</th><th style="width:220px;">' . $this->l('Order status') . '</th><th>' . $this->l('Message template') . '</th></tr></thead><tbody>';

        foreach ($states as $state) {
            $id = (int) $state['id_order_state'];
            $name = htmlspecialchars((string) $state['name'], ENT_QUOTES, 'UTF-8');
            $enabled = !empty($map[$id]['enabled']);
            $template = isset($map[$id]['template']) ? (string) $map[$id]['template'] : '';

            $html .= '<tr>';
            $html .= '<td style="text-align:center; vertical-align:middle;"><input type="checkbox" name="sms_state_enabled_' . $id . '" value="1" ' . ($enabled ? 'checked="checked"' : '') . '></td>';
            $html .= '<td style="vertical-align:middle;">' . $name . '</td>';
            $html .= '<td><textarea name="sms_state_tpl_' . $id . '" class="form-control" rows="2" style="width:100%;">' . htmlspecialchars($template, ENT_QUOTES, 'UTF-8') . '</textarea></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= '<button type="submit" name="submitMessageGlobeSmsStates" class="btn btn-default"><i class="icon-save"></i> ' . $this->l('Save order-status templates') . '</button>';
        $html .= '</form></div>';

        return $html;
    }

    protected function renderSmsTestPanel()
    {
        $ajaxUrl = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name
            . '&ajax=1';

        $ajaxUrlJson = json_encode($ajaxUrl);
        $labelsJson = json_encode([
            'sending' => $this->l('Sending test SMS...'),
            'failed' => $this->l('SMS test failed.'),
        ]);

        return '
            <div class="panel">
                <h3><i class="icon-mobile"></i> ' . $this->l('Send test SMS') . '</h3>
                <p class="help-block">' . $this->l('Sends a one-off SMS using the saved sender ID and gateway. Save your SMS settings first.') . '</p>
                <div class="form-group">
                    <input type="text" id="messageglobe-sms-test-to" class="form-control" placeholder="' . $this->l('Phone number, e.g. 393331234567') . '">
                </div>
                <div class="form-group">
                    <textarea id="messageglobe-sms-test-message" class="form-control" rows="2">' . htmlspecialchars($this->l('Message Globe test message.'), ENT_QUOTES, 'UTF-8') . '</textarea>
                </div>
                <button type="button" id="messageglobe-sms-test" class="btn btn-default">
                    <i class="icon-mobile"></i> ' . $this->l('Send test SMS') . '
                </button>
                <div id="messageglobe-sms-test-status" class="alert" style="display:none; margin-top:12px;"></div>
            </div>
            <script>
            (function () {
                var ajaxUrl = ' . $ajaxUrlJson . ';
                var labels = ' . $labelsJson . ';
                var button = document.getElementById("messageglobe-sms-test");
                var status = document.getElementById("messageglobe-sms-test-status");

                if (!button) {
                    return;
                }

                button.addEventListener("click", function () {
                    button.disabled = true;
                    status.style.display = "block";
                    status.className = "alert alert-info";
                    status.innerHTML = labels.sending;

                    var data = new FormData();
                    data.append("ajax", "1");
                    data.append("messageglobe_action", "sms_test");
                    data.append("to", (document.getElementById("messageglobe-sms-test-to") || {}).value || "");
                    data.append("message", (document.getElementById("messageglobe-sms-test-message") || {}).value || "");

                    fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: data })
                        .then(function (response) { return response.json(); })
                        .then(function (json) {
                            status.className = "alert " + (json.success ? "alert-success" : "alert-danger");
                            status.innerHTML = json.message || (json.success ? "OK" : labels.failed);
                        })
                        .catch(function (error) {
                            status.className = "alert alert-danger";
                            status.innerHTML = labels.failed + " " + error.message;
                        })
                        .then(function () {
                            button.disabled = false;
                        });
                });
            }());
            </script>';
    }

    protected function renderSmsLogPanel()
    {
        $rows = $this->getSmsLogs(25);

        $html = '<div class="panel"><h3><i class="icon-list-alt"></i> ' . $this->l('Recent SMS activity') . '</h3>';

        if (!$rows) {
            return $html . '<p class="help-block">' . $this->l('No SMS sent yet.') . '</p></div>';
        }

        $html .= '<div class="table-responsive"><table class="table"><thead><tr>';
        $html .= '<th>' . $this->l('Date') . '</th>';
        $html .= '<th>' . $this->l('Recipient') . '</th>';
        $html .= '<th>' . $this->l('Status') . '</th>';
        $html .= '<th>' . $this->l('Message') . '</th>';
        $html .= '<th>' . $this->l('Cost') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $row['recipient'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars(Tools::substr((string) $row['message'], 0, 80), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $row['cost'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';

        return $html;
    }

    protected function installDatabase()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . static::TABLE_CONTACT_MAP . '` (
            `id_messageglobe_contact_map` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer` INT UNSIGNED NOT NULL,
            `remote_uid` VARCHAR(64) NOT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `phone` VARCHAR(32) DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_messageglobe_contact_map`),
            UNIQUE KEY `uniq_messageglobe_customer` (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $queueSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . static::TABLE_SYNC_QUEUE . '` (
            `id_messageglobe_sync_queue` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer` INT UNSIGNED NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT "pending",
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_messageglobe_sync_queue`),
            KEY `idx_messageglobe_queue_status` (`status`),
            KEY `idx_messageglobe_queue_customer` (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $logSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . static::TABLE_CRON_LOG . '` (
            `id_messageglobe_cron_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `success` TINYINT(1) NOT NULL DEFAULT 1,
            `processed` INT UNSIGNED NOT NULL DEFAULT 0,
            `synced` INT UNSIGNED NOT NULL DEFAULT 0,
            `failed` INT UNSIGNED NOT NULL DEFAULT 0,
            `retried` INT UNSIGNED NOT NULL DEFAULT 0,
            `remaining` INT UNSIGNED NOT NULL DEFAULT 0,
            `message` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_messageglobe_cron_log`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $smsQueueSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . static::TABLE_SMS_QUEUE . '` (
            `id_messageglobe_sms_queue` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `recipient` VARCHAR(64) NOT NULL,
            `message` TEXT NOT NULL,
            `sender_id` VARCHAR(32) DEFAULT NULL,
            `gateway` VARCHAR(2) DEFAULT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT "pending",
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_messageglobe_sms_queue`),
            KEY `idx_messageglobe_sms_status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $smsLogSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . static::TABLE_SMS_LOG . '` (
            `id_messageglobe_sms_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `recipient` VARCHAR(190) NOT NULL DEFAULT "",
            `message` TEXT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT "",
            `message_id` VARCHAR(64) DEFAULT NULL,
            `cost` DECIMAL(10,4) NOT NULL DEFAULT 0,
            `error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_messageglobe_sms_log`),
            KEY `idx_messageglobe_sms_log_created` (`created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $alterNextAttemptSql = 'ALTER TABLE `' . _DB_PREFIX_ . static::TABLE_SYNC_QUEUE . '` ADD COLUMN `next_attempt_at` DATETIME NULL';

        $db = Db::getInstance();
        $ok = $db->execute($sql) && $db->execute($queueSql) && $db->execute($logSql)
            && $db->execute($smsQueueSql) && $db->execute($smsLogSql);

        $columns = $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . static::TABLE_SYNC_QUEUE . '` LIKE "next_attempt_at"');
        if (empty($columns)) {
            $ok = $ok && $db->execute($alterNextAttemptSql);
        }

        return $ok;
    }

    protected function uninstallDatabase()
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . static::TABLE_CONTACT_MAP . '`';
        $queueSql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . static::TABLE_SYNC_QUEUE . '`';
        $logSql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . static::TABLE_CRON_LOG . '`';
        $smsQueueSql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . static::TABLE_SMS_QUEUE . '`';
        $smsLogSql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . static::TABLE_SMS_LOG . '`';

        $db = Db::getInstance();

        return $db->execute($sql) && $db->execute($queueSql) && $db->execute($logSql)
            && $db->execute($smsQueueSql) && $db->execute($smsLogSql);
    }
}
