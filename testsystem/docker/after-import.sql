-- Wird nach dem Import eines Dumps ausgeführt (./testsystem.sh import).
-- Vorher setzt testsystem.sh: @shop_host (z. B. 'localhost:8080') und @anonymize (1 = E-Mails anonymisieren).
SET NAMES utf8mb4;

-- Domain und HTTPS: alle Hauptshops auf das Testsystem; der Standardshop unter /, weitere unter /shop<ID>
UPDATE s_core_shops SET host = @shop_host, hosts = '', secure = 0, base_path = NULL WHERE main_id IS NULL AND `default` = 1;
UPDATE s_core_shops SET host = @shop_host, hosts = '', secure = 0, base_path = CONCAT('/shop', id) WHERE main_id IS NULL AND `default` = 0;
UPDATE s_core_shops SET host = NULL, secure = 0 WHERE main_id IS NOT NULL;

-- Mail: SMTP-Daten des Live-Shops entfernen (config.php leitet ohnehin alles an Mailpit)
DELETE v FROM s_core_config_values v JOIN s_core_config_elements e ON e.id = v.element_id
WHERE e.name IN ('mailer_mailer', 'mailer_host', 'mailer_port', 'mailer_smtpsecure', 'mailer_username', 'mailer_password', 'mailer_auth');

-- Kunden-E-Mails anonymisieren
UPDATE s_user SET email = CONCAT('kunde-', id, '@example.invalid') WHERE @anonymize = 1;
UPDATE s_campaigns_mailaddresses SET email = CONCAT('newsletter-', id, '@example.invalid') WHERE @anonymize = 1;
