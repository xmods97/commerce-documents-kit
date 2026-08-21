<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Throwable;
use Xmods\CommerceDocuments\Application\DeliverDocument;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\IdempotencyKey;
use Xmods\CommerceDocuments\WordPress\AuditChainVerifier;
use Xmods\CommerceDocuments\WordPress\ConfigKeyProvider;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\Installer;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;
use Xmods\CommerceDocuments\WordPress\WpdbEventLogger;
use Xmods\CommerceDocuments\WordPress\WpdbNumberGenerator;
use Xmods\CommerceDocuments\WordPress\SandboxMailer;
use Xmods\CommerceDocuments\WordPress\NativeMediaLibrary;
use Xmods\CommerceDocuments\Rendering\EmbeddedFontPdfRenderer;
use Xmods\CommerceDocuments\Rendering\DesignCatalog;
use Xmods\CommerceDocuments\Rendering\HtmlRenderer;
use Xmods\CommerceDocuments\Rendering\TemplateCatalog;
use Xmods\CommerceDocuments\WordPress\WordPressLogoProvider;

final class AdminController
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_commerce_documents_generate', [self::class, 'generate']);
        add_action('admin_post_commerce_documents_generate_order_confirmation', [self::class, 'generateOrderConfirmation']);
        add_action('admin_post_commerce_documents_generate_cod', [self::class, 'generateCod']);
        add_action('admin_post_commerce_documents_view', [self::class, 'view']);
        // Read-only PDF access: an administrator previews or downloads one
        // document. It is not reachable from any order hook or delivery path.
        add_action('admin_post_commerce_documents_preview_pdf', [self::class, 'previewPdf']);
        add_action('admin_post_commerce_documents_download_pdf', [self::class, 'downloadPdf']);
        add_action('admin_post_commerce_documents_sandbox_email', [self::class, 'sandboxEmail']);
        add_action('admin_post_commerce_documents_migrate', [self::class, 'migrate']);
        add_action('admin_post_commerce_documents_correct', [self::class, 'correct']);
        add_action('admin_post_commerce_documents_set_admin_language', [self::class, 'setAdminLanguage']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            self::t('plugin_title'),
            self::t('plugin_title'),
            'manage_woocommerce',
            'commerce-documents',
            [self::class, 'page']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('commerce_documents', 'commerce_documents_wc_settings', [
            'type' => 'array',
            'sanitize_callback' => static function ($input): array {
                $statuses = array_map(
                    static function (string $status): string {
                        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
                    },
                    array_keys(function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [])
                );
                return AdminSettings::sanitize((array) $input, $statuses);
            },
        ]);
        // This is the actual option read by Plugin::observeOrderStatus(). Keep
        // the admin control and the runtime gate on the same setting.
        register_setting('commerce_documents', 'commerce_documents_wc_order_confirmation_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => static function ($value): bool {
                return (string) $value === '1';
            },
            'default' => false,
        ]);
        register_setting('commerce_documents', 'commerce_documents_wc_payment_confirmation_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => static function ($value): bool {
                return (string) $value === '1';
            },
            'default' => false,
        ]);
    }

    private const ADMIN_LANGUAGE_META = 'commerce_documents_admin_language';

    public static function setAdminLanguage(): void
    {
        self::authorize('commerce_documents_set_admin_language');
        $language = isset($_POST['language']) ? sanitize_key(wp_unslash($_POST['language'])) : '';
        if (!in_array($language, ['pl', 'ru'], true)) {
            self::redirect('failed', 'Unsupported interface language.');
        }
        update_user_meta(get_current_user_id(), self::ADMIN_LANGUAGE_META, $language);
        self::redirect('language_saved');
    }

    private static function adminLanguage(): string
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $stored = $userId > 0 && function_exists('get_user_meta')
            ? (string) get_user_meta($userId, self::ADMIN_LANGUAGE_META, true)
            : '';
        if (in_array($stored, ['pl', 'ru'], true)) {
            return $stored;
        }
        $locale = function_exists('get_user_locale') ? (string) get_user_locale() : '';
        return strpos($locale, 'ru') === 0 ? 'ru' : 'pl';
    }

    private static function t(string $key): string
    {
        $strings = [
            'pl' => [
                'interface_language' => "Język panelu",
                'apply' => "Zastosuj",
                'polish' => "Polski",
                'russian' => "Rosyjski",
                'local_beta' => "Lokalna wersja beta",
                'hero_description' => "Wewnętrzne potwierdzenia zamówień, chronione kopie i bezpieczny lokalny podgląd.",
                'rules_enabled' => "Automatyczne reguły dokumentów są włączone",
                'rules_paused' => "Automatyczne tworzenie jest wstrzymane",
                'local_mode' => "Tryb lokalny.",
                'local_mode_description' => "Dokumenty, podglądy PDF i pliki sandbox .eml pozostają lokalnie. Nie wysyłamy poczty zewnętrznej ani danych do Fakturownia lub KSeF.",
                'settings_nav' => "Ustawienia",
                'quick_nav' => "Szybkie działania",
                'documents_nav' => "Dokumenty",
                'documents_shown' => "Widoczne dokumenty",
                'latest_records' => "Najnowsze chronione rekordy",
                'filtered_result' => "Wynik filtrowania",
                'readable_snapshots' => "Czytelne kopie",
                'encrypted_preview' => "Zaszyfrowane i gotowe do podglądu",
                'seller_profile' => "Profil sprzedawcy",
                'paused' => "Wstrzymane",
                'rules_disabled' => "Automatyczne reguły dokumentów są wyłączone",
                'ready' => "Gotowe",
                'fields_complete' => "Wymagane pola są uzupełnione",
                'needs_setup' => "Wymaga konfiguracji",
                'complete_settings' => "Uzupełnij dane sprzedawcy i włączone dokumenty",
                'database_schema' => "Schemat bazy",
                'migration_required' => "Wymagana migracja",
                'current' => "Aktualny",
                'settings_title' => "Ustawienia dokumentów",
                'settings_description' => "Wybierz dane sprzedawcy, język PDF i zasady tworzenia potwierdzeń.",
                'seller' => "Sprzedawca",
                'use_store_details' => "Użyj danych sklepu WooCommerce",
                'enter_manually' => "Wprowadź dane sprzedawcy ręcznie",
                'store_details_description' => "WooCommerce dostarcza nazwę, e-mail i adres sklepu. Identyfikator podatkowy pozostaje osobnym polem.",
                'company_name' => "Nazwa firmy",
                'tax_identifier' => "NIP",
                'email' => "E-mail",
                'address_line_1' => "Adres — linia 1",
                'address_line_2' => "Adres — linia 2",
                'postal_code' => "Kod pocztowy",
                'city' => "Miasto",
                'region' => "Województwo / region",
                'country_code' => "Kod kraju",
                'pdf_language' => "Język dokumentu PDF",
                'polish_pdf' => "Polski",
                'english_pdf' => "English",
                'pdf_design' => "Układ dokumentu PDF",
                'design_description' => "Dotyczy nowych dokumentów. Istniejące dokumenty zachowują swój zapisany układ.",
                'order_confirmation_heading' => "1. Potwierdzenie zamówienia — po przejściu checkoutu",
                'enable_order_confirmation' => "Twórz potwierdzenie zamówienia po wybranym statusie",
                'order_confirmation_description' => "Ten dokument nie wymaga daty płatności i otrzymuje oznaczenie NIEOPŁACONE. To potwierdzenie wewnętrzne, a nie faktura.",
                'wc_status' => "Status WooCommerce",
                'create_order_confirmation' => "Twórz potwierdzenie zamówienia",
                'payment_confirmation_heading' => "2. Potwierdzenie płatności — po potwierdzeniu płatności",
                'enable_payment_confirmation' => "Twórz potwierdzenie płatności, gdy WooCommerce potwierdzi płatność",
                'payment_confirmation_description' => "Wymagany jest wybrany status oraz data płatności w WooCommerce. Dokument otrzymuje oznaczenie OPŁACONE.",
                'create_payment_confirmation' => "Twórz potwierdzenie płatności",
                'offline_gateways' => "Bramki offline (pobranie, przelew)",
                'never_paid' => "Nigdy nie traktuj jako opłacone (zalecane)",
                'cod_manual' => "Pobranie jest ręczne. Automatyczny hook nigdy nie tworzy dla niego potwierdzenia płatności.",
                'save_settings' => "Zapisz ustawienia dokumentów",
                'quick_title' => "Szybkie działania",
                'quick_description' => "Używaj ich tylko dla istniejącego zamówienia. Istniejące dokumenty są niezmienne i nie są nadpisywane.",
                'order_created' => "Zamówienie utworzone",
                'order_created_description' => "Utwórz nieopłacone potwierdzenie dla zamówienia bez daty płatności.",
                'payment_confirmed' => "Płatność potwierdzona",
                'payment_confirmed_description' => "Utwórz osobne potwierdzenie tylko wtedy, gdy WooCommerce zapisał datę płatności.",
                'cash_on_delivery' => "Pobranie",
                'cod_description' => "Utwórz wyraźnie nieopłacone potwierdzenie dla aktywnego, nieopłaconego zamówienia pobraniowego.",
                'order_id' => "ID zamówienia",
                'create_unpaid' => "Utwórz nieopłacone potwierdzenie",
                'create_paid' => "Utwórz opłacone potwierdzenie",
                'create_unpaid_cod' => "Utwórz nieopłacone potwierdzenie pobrania",
                'documents_title' => "Utworzone dokumenty",
                'documents_description' => "Szukaj po zamówieniu, typie lub ID dokumentu. Numery dokumentów są zaszyfrowane.",
                'search_placeholder' => "Numer, zamówienie, typ lub ID dokumentu",
                'search' => "Szukaj",
                'schema' => "Schemat bazy",
                'target' => "Cel",
                'target' => "Cel",
                'backup_confirmed' => "Potwierdzam wykonanie aktualnej kopii bazy.",
                'apply_migration' => "Zastosuj chronioną migrację",
                'schema_current' => "Schemat jest aktualny. Migracja nie jest wymagana.",
                'migration_blocked' => "Migracja jest zablokowana do czasu rozwiązania błędów.",
                'no_documents' => "Nie znaleziono dokumentów.",
                'generate_from_quick' => "Utwórz chronione potwierdzenie w sekcji szybkich działań.",
                'number' => "Numer",
                'type' => "Typ",
                'order' => "Zamówienie",
                'created' => "Utworzono",
                'state' => "Stan",
                'audit' => "Audyt",
                'actions' => "Działania",
                'issued' => "Wydany",
                'replaced' => "Zastąpiony",
                'unreadable' => "Nieczytelny",
                'view' => "Podgląd",
                'preview_pdf' => "Podgląd PDF",
                'download_pdf' => "Pobierz PDF",
                'sandbox_email' => "Sandbox e-mail",
                'correction' => "Korekta",
                'buyer_name' => "Poprawiona nazwa nabywcy",
                'correction_note' => "Opis korekty",
                'language_saved' => "Język panelu został zapisany.",
                'generated' => "Dokument utworzono lub już istniał.",
                'sandbox_created' => "Utworzono lokalny plik sandbox .eml. Żaden e-mail nie został wysłany.",
                'payment_statuses_empty' => "Potwierdzenie płatności jest włączone, ale nie wybrano statusów.",
                'select_status_or_disable' => "Wybierz co najmniej jeden status albo wyłącz ten typ dokumentu przed zapisaniem.",
                'offline_methods_placeholder' => "cod, bacs",
                'offline_description' => "WooCommerce nie zapisuje daty płatności dla metod offline, dlatego są domyślnie nieopłacone. Używaj ich tylko przy ręcznym tworzeniu potwierdzenia pobrania.",
                'rollback_manual' => "Cofnięcie migracji jest ręczne. Installer::rollbackPlan() pokaże dokładne instrukcje dla przywróconej kopii bazy.",
                'sections' => "Sekcje dokumentów Commerce Documents",
                'plugin_title' => "Commerce Documents",
                'operation_failed' => "Operacja nie powiodła się.",
            ],
            'ru' => [
                'interface_language' => "Язык панели",
                'apply' => "Применить",
                'polish' => "Польский",
                'russian' => "Русский",
                'local_beta' => "Локальная beta-версия",
                'hero_description' => "Внутренние подтверждения заказов, защищённые копии и безопасный локальный просмотр.",
                'rules_enabled' => "Автоматические правила документов включены",
                'rules_paused' => "Автоматическое создание приостановлено",
                'local_mode' => "Локальный режим.",
                'local_mode_description' => "Документы, PDF-просмотр и sandbox-файлы .eml остаются локальными. Внешняя почта, Fakturownia и KSeF не используются.",
                'settings_nav' => "Настройки",
                'quick_nav' => "Быстрые действия",
                'documents_nav' => "Документы",
                'documents_shown' => "Документов показано",
                'latest_records' => "Последние защищённые записи",
                'filtered_result' => "Результат фильтра",
                'readable_snapshots' => "Доступные копии",
                'encrypted_preview' => "Зашифрованы и доступны для просмотра",
                'seller_profile' => "Профиль продавца",
                'paused' => "Приостановлено",
                'rules_disabled' => "Автоматические правила документов отключены",
                'ready' => "Готово",
                'fields_complete' => "Обязательные поля заполнены",
                'needs_setup' => "Требует настройки",
                'complete_settings' => "Заполните данные продавца и включённые типы документов",
                'database_schema' => "Схема базы",
                'migration_required' => "Требуется миграция",
                'current' => "Актуальна",
                'settings_title' => "Настройки документов",
                'settings_description' => "Выберите данные продавца, язык PDF и правила создания подтверждений.",
                'seller' => "Продавец",
                'use_store_details' => "Использовать данные магазина WooCommerce",
                'enter_manually' => "Ввести данные продавца вручную",
                'store_details_description' => "WooCommerce передаёт название, e-mail и адрес магазина. Налоговый идентификатор остаётся отдельным полем.",
                'company_name' => "Название компании",
                'tax_identifier' => "Налоговый номер",
                'email' => "E-mail",
                'address_line_1' => "Адрес — строка 1",
                'address_line_2' => "Адрес — строка 2",
                'postal_code' => "Индекс",
                'city' => "Город",
                'region' => "Регион",
                'country_code' => "Код страны",
                'pdf_language' => "Язык PDF-документа",
                'polish_pdf' => "Польский",
                'english_pdf' => "English",
                'pdf_design' => "Макет PDF-документа",
                'design_description' => "Относится к новым документам. Существующие документы сохраняют свой выбранный макет.",
                'order_confirmation_heading' => "1. Подтверждение заказа — после оформления",
                'enable_order_confirmation' => "Создавать подтверждение заказа при выбранном статусе",
                'order_confirmation_description' => "Этот документ не требует даты оплаты и получает отметку НЕ ОПЛАЧЕНО. Это внутреннее подтверждение, а не счёт-фактура.",
                'wc_status' => "Статус WooCommerce",
                'create_order_confirmation' => "Создавать подтверждение заказа",
                'payment_confirmation_heading' => "2. Подтверждение оплаты — после подтверждения оплаты",
                'enable_payment_confirmation' => "Создавать подтверждение оплаты, когда WooCommerce подтвердил оплату",
                'payment_confirmation_description' => "Нужны выбранный статус и дата оплаты в WooCommerce. Документ получает отметку ОПЛАЧЕНО.",
                'create_payment_confirmation' => "Создавать подтверждение оплаты",
                'offline_gateways' => "Офлайн-способы (наложенный платёж, перевод)",
                'never_paid' => "Никогда не считать оплаченным (рекомендуется)",
                'cod_manual' => "Наложенный платёж обрабатывается вручную. Автоматический hook не создаёт для него подтверждение оплаты.",
                'save_settings' => "Сохранить настройки документов",
                'quick_title' => "Быстрые действия",
                'quick_description' => "Используйте их только для существующего заказа. Существующие документы неизменяемы и не перезаписываются.",
                'order_created' => "Заказ создан",
                'order_created_description' => "Создать неоплаченное подтверждение для заказа без даты оплаты.",
                'payment_confirmed' => "Оплата подтверждена",
                'payment_confirmed_description' => "Создать отдельное подтверждение только если WooCommerce сохранил дату оплаты.",
                'cash_on_delivery' => "Наложенный платёж",
                'cod_description' => "Создать явно неоплаченное подтверждение для активного неоплаченного заказа с наложенным платежом.",
                'order_id' => "ID заказа",
                'create_unpaid' => "Создать неоплаченное подтверждение",
                'create_paid' => "Создать подтверждение оплаты",
                'create_unpaid_cod' => "Создать неоплаченное подтверждение наложенного платежа",
                'documents_title' => "Созданные документы",
                'documents_description' => "Поиск по заказу, типу или ID документа. Номера документов зашифрованы.",
                'search_placeholder' => "Номер, заказ, тип или ID документа",
                'search' => "Найти",
                'schema' => "Схема базы",
                'target' => "цель",
                'target' => "цель",
                'backup_confirmed' => "Я проверил актуальную резервную копию базы.",
                'apply_migration' => "Применить защищённую миграцию",
                'schema_current' => "Схема актуальна. Миграция не требуется.",
                'migration_blocked' => "Миграция заблокирована до устранения ошибок.",
                'no_documents' => "Документы не найдены.",
                'generate_from_quick' => "Создайте защищённое подтверждение в разделе быстрых действий.",
                'number' => "Номер",
                'type' => "Тип",
                'order' => "Заказ",
                'created' => "Создан",
                'state' => "Состояние",
                'audit' => "Аудит",
                'actions' => "Действия",
                'issued' => "Выпущен",
                'replaced' => "Заменён",
                'unreadable' => "Недоступен",
                'view' => "Просмотр",
                'preview_pdf' => "Просмотр PDF",
                'download_pdf' => "Скачать PDF",
                'sandbox_email' => "Sandbox e-mail",
                'correction' => "Коррекция",
                'buyer_name' => "Исправленное имя покупателя",
                'correction_note' => "Причина коррекции",
                'language_saved' => "Язык панели сохранён.",
                'generated' => "Документ создан или уже существовал.",
                'sandbox_created' => "Локальный sandbox-файл .eml создан. Письмо не отправлялось.",
                'payment_statuses_empty' => "Подтверждение оплаты включено, но статусы не выбраны.",
                'select_status_or_disable' => "Выберите хотя бы один статус или отключите этот тип документа перед сохранением.",
                'offline_methods_placeholder' => "cod, bacs",
                'offline_description' => "WooCommerce не записывает дату оплаты для офлайн-методов, поэтому они по умолчанию считаются неоплаченными. Используйте их только для ручного создания подтверждения наложенного платежа.",
                'rollback_manual' => "Откат миграции выполняется вручную. Installer::rollbackPlan() покажет точные инструкции для восстановленной копии базы.",
                'sections' => "Разделы Commerce Documents",
                'plugin_title' => "Документы Commerce",
                'operation_failed' => "Операция не выполнена.",
            ],
        ];
        return $strings[self::adminLanguage()][$key] ?? $key;
    }

    private static function languageSwitcher(): string
    {
        $language = self::adminLanguage();
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cdk-language-switcher">'
            . '<input type="hidden" name="action" value="commerce_documents_set_admin_language">'
            . wp_nonce_field('commerce_documents_set_admin_language', '_wpnonce', true, false)
            . '<label for="cdk-admin-language">' . esc_html(self::t('interface_language')) . '</label>'
            . '<select id="cdk-admin-language" name="language">'
            . '<option value="pl" ' . selected($language, 'pl', false) . '>' . esc_html(self::t('polish')) . '</option>'
            . '<option value="ru" ' . selected($language, 'ru', false) . '>' . esc_html(self::t('russian')) . '</option>'
            . '</select><button class="button" type="submit">' . esc_html(self::t('apply')) . '</button></form>';

    }
    public static function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage commerce documents.', 'commerce-documents-woocommerce'));
        }
        $settings = (array) get_option('commerce_documents_wc_settings', []);
        $sellerSource = ($settings['seller_source'] ?? 'manual') === 'woocommerce'
            ? 'woocommerce'
            : 'manual';
        $seller = AdminSettings::resolveSeller(
            $settings,
            static function (string $name, $default) {
                return get_option($name, $default);
            }
        );
        $address = (array) ($seller['address'] ?? []);
        $orderConfirmationStatuses = (array) (
            $settings['order_confirmation_statuses']
                ?? $settings['paid_statuses']
                ?? ['pending', 'on-hold', 'processing']
        );
        $paymentConfirmationStatuses = (array) (
            $settings['payment_confirmation_statuses']
                ?? $settings['paid_statuses']
                ?? PaidOrderPolicy::DEFAULT_PAID_STATUSES
        );
        $codPolicy = (string) ($settings['cod_policy'] ?? PaidOrderPolicy::COD_POLICY_NEVER);
        $offlineMethods = implode(', ', (array) ($settings['cod_offline_methods'] ?? []));
        $enabled = (string) get_option('commerce_documents_wc_order_confirmation_enabled', '0') === '1';
        $paymentEnabled = (string) get_option('commerce_documents_wc_payment_confirmation_enabled', '0') === '1';
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $search = isset($_GET['cdk_search']) ? sanitize_text_field(wp_unslash($_GET['cdk_search'])) : '';
        $documents = self::documents($search);
        $migration = Installer::preflight();
        $resolvedSettings = $settings;
        $resolvedSettings['seller'] = $seller;
        $settingsComplete = AdminSettings::isComplete($resolvedSettings, $enabled, $paymentEnabled);
        $automationEnabled = $enabled || $paymentEnabled;
        $sellerReadiness = !$automationEnabled
            ? [self::t('paused'), self::t('rules_disabled')]
            : ($settingsComplete
                ? [self::t('ready'), self::t('fields_complete')]
                : [self::t('needs_setup'), self::t('complete_settings')]);
        $readableCount = count(array_filter($documents, static function (array $document): bool {
            return !empty($document['readable']);
        }));

        echo '<div class="wrap cdk-admin">';
        self::styles();
        echo '<section class="cdk-hero"><div><p class="cdk-eyebrow">WooCommerce · ' . esc_html(self::t('local_beta')) . '</p>'
            . '<h1>' . esc_html(self::t('plugin_title')) . '</h1>'
            . '<p>' . esc_html(self::t('hero_description')) . '</p></div>'
            . '<div class="cdk-hero__tools"><span class="cdk-status ' . ($automationEnabled ? 'is-enabled' : 'is-disabled') . '">'
            . ($enabled || $paymentEnabled ? esc_html(self::t('rules_enabled')) : esc_html(self::t('rules_paused')))
            . '</span>' . self::languageSwitcher() . '</div></section>';
        self::notice();
        echo '<div class="cdk-callout"><strong>' . esc_html(self::t('local_mode')) . '</strong> '
            . esc_html(self::t('local_mode_description')) . '</div>';
        echo '<nav class="cdk-nav" aria-label="' . esc_attr(self::t('sections')) . '">'
            . '<a href="#cdk-settings">' . esc_html(self::t('settings_nav')) . '</a>'
            . '<a href="#cdk-quick-actions">' . esc_html(self::t('quick_nav')) . '</a>'
            . '<a href="#cdk-documents">' . esc_html(self::t('documents_nav')) . '</a></nav>';
        echo '<div class="cdk-summary">'
            . self::summaryCard(self::t('documents_shown'), (string) count($documents), $search === '' ? self::t('latest_records') : self::t('filtered_result'))
            . self::summaryCard(self::t('readable_snapshots'), (string) $readableCount, self::t('encrypted_preview'))
            . self::summaryCard(self::t('seller_profile'), $sellerReadiness[0], $sellerReadiness[1])
            . self::summaryCard(self::t('database_schema'), (string) $migration['installed_version'] . ' / ' . (string) $migration['target_version'], $migration['upgrade_required'] ? self::t('migration_required') : self::t('current'))
            . '</div>';

        echo '<section class="cdk-card" id="cdk-settings"><div class="cdk-card__head"><div><h2>' . esc_html(self::t('settings_title')) . '</h2>'
            . '<p>' . esc_html(self::t('settings_description')) . '</p></div></div>';
        echo '<form method="post" action="options.php">';
        settings_fields('commerce_documents');
        echo '<h3>' . esc_html(self::t('seller')) . '</h3><fieldset class="cdk-choice-row"><label><input type="radio" name="commerce_documents_wc_settings[seller_source]" value="woocommerce" '
            . checked($sellerSource, 'woocommerce', false) . '> ' . esc_html(self::t('use_store_details')) . '</label><br>'
            . '<label><input type="radio" name="commerce_documents_wc_settings[seller_source]" value="manual" '
            . checked($sellerSource, 'manual', false) . '> ' . esc_html(self::t('enter_manually')) . '</label></fieldset>'
            . '<p class="description">' . esc_html(self::t('store_details_description')) . '</p>'
            . '<table class="form-table">';
        self::field(self::t('company_name'), 'name', (string) ($seller['name'] ?? ''), 'text', $sellerSource === 'woocommerce');
        self::field(self::t('tax_identifier'), 'tax_identifier', (string) ($seller['tax_identifier'] ?? ''));
        self::field(self::t('email'), 'email', (string) ($seller['email'] ?? ''), 'email', $sellerSource === 'woocommerce');
        self::addressField(self::t('address_line_1'), 'line1', (string) ($address['line1'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField(self::t('address_line_2'), 'line2', (string) ($address['line2'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField(self::t('postal_code'), 'postal_code', (string) ($address['postal_code'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField(self::t('city'), 'city', (string) ($address['city'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField(self::t('region'), 'region', (string) ($address['region'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField(self::t('country_code'), 'country_code', (string) ($address['country_code'] ?? ''), $sellerSource === 'woocommerce');
        self::sellerSourceScript();
        echo '<tr><th>' . esc_html(self::t('pdf_language')) . '</th><td><select name="commerce_documents_wc_settings[language]">';
        foreach (['pl-PL' => self::t('polish_pdf'), 'en' => self::t('english_pdf')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['language'] ?? 'pl-PL', $value, false) . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>' . esc_html(self::t('pdf_design')) . '</th><td><select name="commerce_documents_wc_settings[pdf_design]">';
        foreach (DesignCatalog::options(self::adminLanguage()) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['pdf_design'] ?? DesignCatalog::CLASSIC, $value, false) . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">' . esc_html(self::t('design_description')) . '</p></td></tr></table><hr class="cdk-divider"><h3>' . esc_html(self::t('order_confirmation_heading')) . '</h3>';
        echo '<input type="hidden" name="commerce_documents_wc_order_confirmation_enabled" value="0">';
        echo '<label><input type="checkbox" name="commerce_documents_wc_order_confirmation_enabled" value="1" '
            . checked($enabled, true, false) . '> ' . esc_html(self::t('enable_order_confirmation')) . '</label>';
        echo '<p class="description">' . esc_html(self::t('order_confirmation_description')) . '</p>';
        echo '<input type="hidden" name="commerce_documents_wc_settings[order_confirmation_statuses_present]" value="1">';
        echo '<table class="widefat striped cdk-status-table"><thead><tr>'
            . '<th>' . esc_html(self::t('wc_status')) . '</th><th>' . esc_html(self::t('create_order_confirmation')) . '</th></tr></thead><tbody>';
        foreach ($statuses as $key => $label) {
            $status = strpos($key, 'wc-') === 0 ? substr($key, 3) : $key;
            echo '<tr><td>' . esc_html($label) . '</td><td><input type="checkbox" name="commerce_documents_wc_settings[order_confirmation_statuses][]" value="'
                . esc_attr($status) . '" ' . checked(in_array($status, $orderConfirmationStatuses, true), true, false)
                . '></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>' . esc_html(self::t('payment_confirmation_heading')) . '</h3>';
        echo '<input type="hidden" name="commerce_documents_wc_payment_confirmation_enabled" value="0">';
        echo '<label><input type="checkbox" name="commerce_documents_wc_payment_confirmation_enabled" value="1" '
            . checked($paymentEnabled, true, false) . '> ' . esc_html(self::t('enable_payment_confirmation')) . '</label>';
        echo '<p class="description">' . esc_html(self::t('payment_confirmation_description')) . '</p>';
        echo '<input type="hidden" name="commerce_documents_wc_settings[payment_confirmation_statuses_present]" value="1">';
        echo '<table class="widefat striped cdk-status-table"><thead><tr>'
            . '<th>' . esc_html(self::t('wc_status')) . '</th><th>' . esc_html(self::t('create_payment_confirmation')) . '</th></tr></thead><tbody>';
        foreach ($statuses as $key => $label) {
            $status = strpos($key, 'wc-') === 0 ? substr($key, 3) : $key;
            echo '<tr><td>' . esc_html($label) . '</td><td><input type="checkbox" name="commerce_documents_wc_settings[payment_confirmation_statuses][]" value="'
                . esc_attr($status) . '" ' . checked(in_array($status, $paymentConfirmationStatuses, true), true, false)
                . '></td></tr>';
        }
        echo '</tbody></table>';
        if ($paymentEnabled && $paymentConfirmationStatuses === []) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html(self::t('payment_statuses_empty')) . '</strong> '
                . esc_html(self::t('select_status_or_disable')) . '</p></div>';
        }
        echo '<table class="form-table"><tr><th>' . esc_html(self::t('offline_gateways')) . '</th><td>'
            . '<label><input type="radio" name="commerce_documents_wc_settings[cod_policy]" value="'
            . esc_attr(PaidOrderPolicy::COD_POLICY_NEVER) . '" '
            . checked($codPolicy, PaidOrderPolicy::COD_POLICY_NEVER, false)
            . '> ' . esc_html(self::t('never_paid')) . '</label><br>'
            . '<p><strong>' . esc_html(self::t('cash_on_delivery')) . ':</strong> ' . esc_html(self::t('cod_manual')) . '</p>'
            . '<p><input class="regular-text" type="text" name="commerce_documents_wc_settings[cod_offline_methods]" value="'
            . esc_attr($offlineMethods) . '" placeholder="' . esc_attr(self::t('offline_methods_placeholder')) . '"></p>'
            . '<p class="description">' . esc_html(self::t('offline_description')) . '</p>'
            . '</td></tr></table>';
        submit_button(self::t('save_settings'));
        echo '</form></section>';

        echo '<section class="cdk-card cdk-quick-actions" id="cdk-quick-actions"><div class="cdk-card__head"><div><h2>' . esc_html(self::t('quick_title')) . '</h2>'
            . '<p>' . esc_html(self::t('quick_description')) . '</p></div></div>'
            . '<div class="cdk-action-grid"><div><h3>' . esc_html(self::t('order_created')) . '</h3><p>' . esc_html(self::t('order_created_description')) . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="commerce_documents_generate_order_confirmation">';
        wp_nonce_field('commerce_documents_generate_order_confirmation');
        echo '<label for="cdk-order-created">' . esc_html(self::t('order_id')) . '</label><input id="cdk-order-created" type="number" min="1" required name="order_id"> ';
        submit_button(self::t('create_unpaid'), 'secondary', 'submit', false);
        echo '</form></div><div><h3>' . esc_html(self::t('payment_confirmed')) . '</h3><p>' . esc_html(self::t('payment_confirmed_description')) . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="commerce_documents_generate">';
        wp_nonce_field('commerce_documents_generate');
        echo '<label for="cdk-paid-order">' . esc_html(self::t('order_id')) . '</label><input id="cdk-paid-order" type="number" min="1" required name="order_id"> ';
        submit_button(self::t('create_paid'), 'secondary', 'submit', false);
        echo '</form></div><div><h3>' . esc_html(self::t('cash_on_delivery')) . '</h3><p>' . esc_html(self::t('cod_description')) . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="commerce_documents_generate_cod">';
        wp_nonce_field('commerce_documents_generate_cod');
        echo '<label for="cdk-cod-order">' . esc_html(self::t('order_id')) . '</label><input id="cdk-cod-order" type="number" min="1" required name="order_id"> ';
        submit_button(self::t('create_unpaid_cod'), 'secondary', 'submit', false);
        echo '</form></div></div></section>';

        echo '<section class="cdk-card" id="cdk-documents"><div class="cdk-card__head cdk-card__head--documents"><div><h2>' . esc_html(self::t('documents_title')) . '</h2>'
            . '<p>' . esc_html(self::t('documents_description')) . '</p></div>';
        echo '<form method="get" class="cdk-search"><input type="hidden" name="page" value="commerce-documents">'
            . '<input type="search" name="cdk_search" value="' . esc_attr($search) . '" placeholder="' . esc_attr(self::t('search_placeholder')) . '"> '
            . '<button class="button">' . esc_html(self::t('search')) . '</button></form></div>';
        echo '<details class="cdk-migration"><summary>' . esc_html(self::t('schema')) . ' · ' . esc_html((string) $migration['installed_version'])
            . ' / ' . esc_html(self::t('target')) . ': ' . esc_html((string) $migration['target_version']) . '</summary>';
        foreach ((array) ($migration['warnings'] ?? []) as $warning) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html((string) $warning) . '</p></div>';
        }
        foreach ((array) ($migration['blockers'] ?? []) as $blocker) {
            echo '<div class="notice notice-error inline"><p>' . esc_html((string) $blocker) . '</p></div>';
        }
        if ($migration['upgrade_required'] && ($migration['blockers'] ?? []) === []) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="commerce_documents_migrate">';
            wp_nonce_field('commerce_documents_migrate');
            echo '<label><input type="checkbox" name="backup_confirmed" value="1" required> ' . esc_html(self::t('backup_confirmed')) . '</label> ';
            submit_button(self::t('apply_migration'), 'secondary', 'submit', false);
            echo '</form>';
            echo '<p class="description">' . esc_html(self::t('rollback_manual')) . '</p>';
        } elseif ($migration['upgrade_required']) {
            echo '<p><strong>' . esc_html(self::t('migration_blocked')) . '</strong></p>';
        } else {
            echo '<p>' . esc_html(self::t('schema_current')) . '</p>';
        }
        echo '</details>';
        if ($documents === []) {
            echo '<div class="cdk-empty"><strong>' . esc_html(self::t('no_documents')) . '</strong><br>' . esc_html(self::t('generate_from_quick')) . '</div>';
        } else {
            echo '<div class="cdk-table-wrap"><table class="widefat striped cdk-documents"><thead><tr><th>' . esc_html(self::t('number')) . '</th><th>' . esc_html(self::t('type')) . '</th><th>' . esc_html(self::t('order')) . '</th><th>' . esc_html(self::t('created')) . '</th>'
                . '<th>' . esc_html(self::t('state')) . '</th><th>' . esc_html(self::t('audit')) . '</th><th>' . esc_html(self::t('actions')) . '</th></tr></thead><tbody>';
            foreach ($documents as $document) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=commerce_documents_view&document_id=' . rawurlencode($document['document_id'])),
                    'commerce_documents_view_' . $document['document_id']
                );
                $pdfUrl = wp_nonce_url(
                    admin_url(
                        'admin-post.php?action=commerce_documents_preview_pdf&document_id='
                        . rawurlencode($document['document_id'])
                    ),
                    'commerce_documents_preview_pdf_' . $document['document_id']
                );
                $downloadPdfUrl = wp_nonce_url(
                    admin_url(
                        'admin-post.php?action=commerce_documents_download_pdf&document_id='
                        . rawurlencode($document['document_id'])
                    ),
                    'commerce_documents_download_pdf_' . $document['document_id']
                );
                $supersededBy = (string) ($document['superseded_by'] ?? '');
                $state = $supersededBy !== ''
                    ? '<span class="cdk-badge cdk-badge--replaced">' . esc_html(self::t('replaced')) . '</span><small>' . esc_html($supersededBy) . '</small>'
                    : ($document['readable']
                        ? '<span class="cdk-badge cdk-badge--issued">' . esc_html(self::t('issued')) . '</span>'
                        : '<span class="cdk-badge cdk-badge--unreadable">' . esc_html(self::t('unreadable')) . '</span>');
                echo '<tr><td>' . esc_html($document['document_number']) . '</td><td>'
                    . esc_html($document['document_type']) . '</td><td>#' . esc_html($document['source_id'])
                    . '</td><td>' . esc_html($document['created_at'])
                    . '</td><td>' . $state
                    . '</td><td><span class="cdk-audit">' . esc_html((string) $document['audit_count']) . '</span></td><td class="cdk-actions"><div class="cdk-action-stack"><a class="button" target="_blank" href="'
                    . esc_url($url) . '">' . esc_html(self::t('view')) . '</a>';
                if ($document['readable']) {
                    echo ' <a class="button" target="_blank" href="' . esc_url($pdfUrl) . '">' . esc_html(self::t('preview_pdf')) . '</a>';
                    echo ' <a class="button" href="' . esc_url($downloadPdfUrl) . '">' . esc_html(self::t('download_pdf')) . '</a>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cdk-inline-form">'
                        . '<input type="hidden" name="action" value="commerce_documents_sandbox_email">'
                        . '<input type="hidden" name="document_id" value="' . esc_attr($document['document_id']) . '">'
                        . wp_nonce_field('commerce_documents_sandbox_email_' . $document['document_id'], '_wpnonce', true, false)
                        . '<button class="button" type="submit">' . esc_html(self::t('sandbox_email')) . '</button></form>';
                }
                if ($supersededBy === '' && $document['readable']) {
                    // The token is minted once per rendered form, so a resubmitted or
                    // double-clicked form resolves to the same idempotency key.
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cdk-correction-form">'
                        . '<input type="hidden" name="action" value="commerce_documents_correct">'
                        . '<input type="hidden" name="document_id" value="' . esc_attr($document['document_id']) . '">'
                        . '<input type="hidden" name="correction_token" value="' . esc_attr(bin2hex(random_bytes(16))) . '">'
                        . '<input type="text" name="correction_buyer_name" maxlength="191" placeholder="' . esc_attr(self::t('buyer_name')) . '">'
                        . '<input type="text" name="correction_note" required maxlength="191" placeholder="' . esc_attr(self::t('correction_note')) . '">'
                        . wp_nonce_field('commerce_documents_correct_' . $document['document_id'], '_wpnonce', true, false)
                        . '<button class="button">' . esc_html(self::t('correction')) . '</button></form>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section></div>';
    }

    public static function generate(): void
    {
        self::authorize('commerce_documents_generate');
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = $orderId > 0 && function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order) {
            self::redirect('invalid_order');
        }
        try {
            Plugin::generateForOrder($order);
            self::redirect('generated');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    public static function view(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        $documentId = isset($_GET['document_id']) ? sanitize_text_field(wp_unslash($_GET['document_id'])) : '';
        check_admin_referer('commerce_documents_view_' . $documentId);
        $snapshot = self::find($documentId);
        if ($snapshot === null) {
            wp_die('Document not found.', '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        $exponent = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : null;
        $html = (new HtmlRenderer(new TemplateCatalog()))->render($snapshot, $exponent);
        $logoUrl = self::htmlLogoUrl();
        if ($logoUrl !== '') {
            $logo = '<img src="' . esc_url($logoUrl) . '" alt="" style="max-width:180px;max-height:58px;object-fit:contain;margin-bottom:12px">';
            $html = str_replace('<header>', '<header>' . $logo, $html);
        }

        $banner = '';
        $supersession = self::supersession($documentId);
        if ($supersession['superseded_by'] !== '') {
            $banner = '<div style="max-width:900px;margin:24px auto;padding:12px 20px;border:2px solid #b32d2e;color:#b32d2e">'
                . '<strong>' . esc_html__('This document has been replaced.', 'commerce-documents-woocommerce') . '</strong> '
                . esc_html(sprintf('Superseded by %s on %s.', $supersession['superseded_by'], $supersession['superseded_at']))
                . '</div>';
        }

        global $wpdb;
        $verification = (new AuditChainVerifier(
            $wpdb,
            $wpdb->prefix . 'commerce_document_events',
            ConfigKeyProvider::auditKey()
        ))->verify($documentId);

        $events = self::events($documentId);
        $legacyAudit = $events !== [] && trim((string) ($events[0]['event_hash'] ?? '')) === '';
        $audit = '<section style="max-width:900px;margin:24px auto;padding:0 20px"><h2>Audit trail</h2>';
        if ($legacyAudit && !$verification['valid']) {
            $audit .= '<p><strong>Legacy audit:</strong> this historical event predates the authenticated hash chain and is not treated as verified. It cannot be used for sandbox delivery.</p>';
        } else {
        $audit .= $verification['valid']
            ? '<p>Chain verified: ' . esc_html((string) $verification['events']) . ' event(s).</p>'
            : '<p style="color:#b32d2e"><strong>Chain verification failed</strong> at position '
                . esc_html((string) $verification['broken_at']) . ' — ' . esc_html($verification['reason']) . '</p>';
        }
        $audit .= '<ul>';
        foreach ($events as $event) {
            $audit .= '<li>' . esc_html($event['created_at'] . ' — ' . $event['event_name']) . '</li>';
        }
        $audit .= '</ul></section>';
        echo str_replace('<body>', '<body>' . $banner, str_replace('</body>', $audit . '</body>', $html));
        exit;
    }

    /**
     * Renders one stored snapshot as a PDF and returns it to the administrator.
     *
     * The PDF is deliberately generated on demand rather than stored separately:
     * the encrypted immutable snapshot is the durable source of truth. Preview
     * and download are both admin-only, nonce-bound, read-only responses.
     */
    public static function previewPdf(): void
    {
        self::streamPdf(false);
    }

    public static function downloadPdf(): void
    {
        self::streamPdf(true);
    }

    private static function streamPdf(bool $download): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        $documentId = isset($_GET['document_id']) ? sanitize_text_field(wp_unslash($_GET['document_id'])) : '';
        $nonceAction = $download ? 'download_pdf' : 'preview_pdf';
        check_admin_referer('commerce_documents_' . $nonceAction . '_' . $documentId);

        $snapshot = self::find($documentId);
        if ($snapshot === null) {
            wp_die('Document not found.', '', ['response' => 404]);
        }

        try {
            $pdf = self::renderSnapshotPdf($snapshot);
        } catch (Throwable $error) {
            wp_die(
                esc_html('The document could not be rendered: ' . $error->getMessage()),
                '',
                ['response' => 500]
            );
        }

        // Anything already buffered would corrupt the binary response.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . self::snapshotPdfFilename($snapshot) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    public static function generateOrderConfirmation(): void
    {
        self::authorize('commerce_documents_generate_order_confirmation');
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = $orderId > 0 && function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order) {
            self::redirect('invalid_order');
        }
        try {
            Plugin::generateOrderConfirmationForOrder($order);
            self::redirect('generated');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    /**
     * Creates a local .eml capture for an administrator. This never calls a
     * transport and intentionally uses a distinct audit event from a real send.
     */
    public static function sandboxEmail(): void
    {
        $documentId = isset($_POST['document_id'])
            ? sanitize_text_field(wp_unslash($_POST['document_id']))
            : '';
        self::authorize('commerce_documents_sandbox_email_' . $documentId);

        $snapshot = self::find($documentId);
        if ($snapshot === null) {
            self::redirect('failed', 'Document not found.');
        }
        $data = $snapshot->toArray();
        $recipient = trim((string) (($data['buyer']['email'] ?? '')));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            self::redirect('failed', 'The document buyer has no valid email address.');
        }

        global $wpdb;
        $verification = (new AuditChainVerifier(
            $wpdb,
            $wpdb->prefix . 'commerce_document_events',
            ConfigKeyProvider::auditKey()
        ))->verify($documentId);
        if (!$verification['valid']) {
            self::redirect('failed', 'The document audit chain is not verified; no sandbox file was created.');
        }
        try {
            $mailer = new SandboxMailer(self::sandboxMailDirectory(), 'sandbox@example.invalid');
            $mailer->purgeExpired(self::sandboxRetentionDays());
            $delivery = new DeliverDocument(
                self::pdfRenderer(),
                $mailer,
                new WpdbEventLogger(
                    $wpdb,
                    $wpdb->prefix . 'commerce_document_events',
                    ConfigKeyProvider::auditKey()
                ),
                'document.sandbox_stored'
            );
            $number = (string) ($data['document_number'] ?? $documentId);
            $delivery->execute(
                $snapshot,
                $recipient,
                'Sandbox preview: ' . $number,
                "Local sandbox capture for document {$number}.\nNo email transport was used."
            );
            self::redirect('sandbox_sent', 'Local sandbox .eml created; no email was sent.');
        } catch (Throwable $error) {
            error_log('Commerce Documents sandbox delivery failed: ' . $error->getMessage());
            self::redirect('failed', 'Sandbox email could not be created.');
        }
    }

    public static function generateCod(): void
    {
        self::authorize('commerce_documents_generate_cod');
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = $orderId > 0 && function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order) {
            self::redirect('invalid_order');
        }
        try {
            Plugin::generateCodForOrder($order);
            self::redirect('generated');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    private static function sandboxMailDirectory(): string
    {
        if (defined('COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR')) {
            $directory = constant('COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR');
            if (is_string($directory) && trim($directory) !== '') {
                return $directory;
            }
        }
        return rtrim(sys_get_temp_dir(), "\\/")
            . DIRECTORY_SEPARATOR . 'commerce-documents-sandbox-mail';
    }

    private static function sandboxRetentionDays(): int
    {
        $days = defined('COMMERCE_DOCUMENTS_SANDBOX_RETENTION_DAYS')
            ? (int) constant('COMMERCE_DOCUMENTS_SANDBOX_RETENTION_DAYS')
            : 7;
        if ($days < 1 || $days > 3650) {
            throw new \RuntimeException('Sandbox retention must be between 1 and 3650 days.');
        }
        return $days;
    }

    /**
     * The printable HTML view may reference the same-origin WordPress upload,
     * while the PDF path remains byte-local and never resolves a URL.
     */
    private static function htmlLogoUrl(): string
    {
        if (!function_exists('wp_get_attachment_image_url')) {
            return '';
        }
        $media = new NativeMediaLibrary();
        $customLogoId = $media->customLogoAttachmentId();
        $ids = array_values(array_filter(
            $customLogoId > 0 ? [$customLogoId] : [$media->themeHeaderLogoAttachmentId()],
            static function ($id): bool {
            return (int) $id > 0;
        }));
        foreach ($ids as $id) {
            $mime = strtolower(trim($media->mimeTypeOf((int) $id)));
            if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
                continue;
            }
            $url = wp_get_attachment_image_url((int) $id, 'full');
            if (!is_string($url) || $url === '') {
                continue;
            }
            $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
            $home = function_exists('home_url') ? (wp_parse_url(home_url('/')) ?: []) : [];
            if (!is_array($parts) || !is_array($home)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))) {
                continue;
            }
            return $url;
        }
        return '';
    }

    /**
     * The one construction of the production renderer.
     *
     * The store's own price precision is used rather than a hard-coded 2 so the
     * amounts on the PDF match the amounts everywhere else in the shop.
     */
    private static function pdfRenderer(): EmbeddedFontPdfRenderer
    {
        return new EmbeddedFontPdfRenderer(
            function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2,
            null,
            null,
            new WordPressLogoProvider()
        );
    }

    /** A filename safe for a Content-Disposition header, derived from the document number. */
    public static function renderSnapshotPdf(DocumentSnapshot $snapshot): string
    {
        return self::pdfRenderer()->render($snapshot);
    }

    /** A filename safe for a Content-Disposition header, derived from the document number. */
    public static function snapshotPdfFilename(DocumentSnapshot $snapshot): string
    {
        $number = (string) ($snapshot->toArray()['document_number'] ?? 'document');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number);
        $name = trim((string) $name, '-');
        return ($name === '' ? 'document' : substr($name, 0, 100)) . '.pdf';
    }

    public static function migrate(): void
    {
        self::authorize('commerce_documents_migrate');
        try {
            Installer::migrateToCurrentVersion(isset($_POST['backup_confirmed']) && (string) $_POST['backup_confirmed'] === '1');
            self::redirect('migrated');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    public static function correct(): void
    {
        $documentId = isset($_POST['document_id']) ? sanitize_text_field(wp_unslash($_POST['document_id'])) : '';
        self::authorize('commerce_documents_correct_' . $documentId);
        $note = isset($_POST['correction_note']) ? sanitize_text_field(wp_unslash($_POST['correction_note'])) : '';
        $buyerName = isset($_POST['correction_buyer_name']) ? sanitize_text_field(wp_unslash($_POST['correction_buyer_name'])) : '';
        if ($documentId === '' || $note === '') {
            self::redirect('failed', 'Document and correction note are required.');
        }
        $token = isset($_POST['correction_token']) ? sanitize_text_field(wp_unslash($_POST['correction_token'])) : '';
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            self::redirect('failed', 'A correction token is required.');
        }

        global $wpdb;
        $documents = $wpdb->prefix . 'commerce_documents';
        $repository = new WpdbDocumentRepository($wpdb, $documents, self::codec());
        $logger = new WpdbEventLogger(
            $wpdb,
            $wpdb->prefix . 'commerce_document_events',
            ConfigKeyProvider::auditKey()
        );

        // The token is minted once per rendered form, so a resubmitted form resolves
        // to the same idempotency key and cannot mint a second correction or burn a
        // second sequence number.
        $sourceId = $documentId . ':' . $token;
        $type = DocumentType::fromString(DocumentType::CORRECTION);
        $key = IdempotencyKey::forSource('commerce_document_correction', $sourceId, $type);
        $correctionId = 'doc_' . substr($key->value(), 0, 24);
        $claimed = false;

        try {
            if ($repository->findByIdempotencyKey($key) !== null) {
                // Same form submitted twice. Nothing to do, and no number consumed.
                self::redirect('corrected');
            }

            $original = self::find($documentId);
            if ($original === null) {
                throw new \RuntimeException('Document not found.');
            }

            // Claim supersession before allocating a number, so two concurrent
            // corrections of the same document cannot both proceed.
            $claim = $wpdb->query($wpdb->prepare(
                "UPDATE {$documents} SET superseded_by = %s, superseded_at = %s
                 WHERE document_id = %s AND superseded_by = ''",
                $correctionId,
                gmdate('Y-m-d H:i:s'),
                $documentId
            ));
            if ((int) $claim !== 1) {
                throw new \RuntimeException('This document has already been corrected.');
            }
            $claimed = true;

            $now = gmdate(DATE_ATOM);
            $data = $original->toArray();
            $data['document_id'] = $correctionId;
            $data['document_number'] = (new WpdbNumberGenerator($wpdb, $wpdb->prefix . 'commerce_document_sequences'))->next($type, $now);
            $data['document_type'] = DocumentType::CORRECTION;
            $data['status'] = DocumentStatus::ISSUED;
            $data['source_type'] = 'commerce_document_correction';
            $data['source_id'] = $sourceId;
            $data['created_at'] = $now;
            $data['issued_at'] = $now;
            $data['version'] = ((int) ($data['version'] ?? 1)) + 1;
            $data['metadata'] = (array) ($data['metadata'] ?? []);
            $data['metadata']['correction_of'] = $documentId;
            $data['metadata']['correction_note'] = $note;
            if ($buyerName !== '') {
                $data['metadata']['corrected_buyer_name_from'] = (string) ($data['buyer']['name'] ?? '');
                $data['buyer']['name'] = $buyerName;
            }
            $snapshot = DocumentSnapshot::fromArray($data);
            $repository->save($key, $snapshot);

            $wpdb->insert($wpdb->prefix . 'commerce_document_links', [
                'document_id' => $correctionId,
                'parent_document_id' => $documentId,
                'relationship' => 'correction',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s']);

            // Both sides of the relationship are recorded. Without the event on the
            // original, its audit trail would look untouched after being replaced.
            $logger->record('document.replaced', $documentId, [
                'superseded_by' => $correctionId,
                'correction_note' => $note,
            ]);
            $logger->record('document.corrected', $correctionId, [
                'parent_document_id' => $documentId,
                'correction_note' => $note,
            ]);
            self::redirect('corrected');
        } catch (Throwable $error) {
            if ($claimed) {
                // Release the claim so the document stays correctable.
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$documents} SET superseded_by = '', superseded_at = NULL
                     WHERE document_id = %s AND superseded_by = %s",
                    $documentId,
                    $correctionId
                ));
            }
            self::redirect('failed', $error->getMessage());
        }
    }

    private static function authorize(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    private static function redirect(string $result, string $message = ''): void
    {
        $url = add_query_arg(
            ['page' => 'commerce-documents', 'cdk_result' => $result, 'cdk_message' => $message],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function documents(string $search = ''): array
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return [];
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $events = $wpdb->prefix . 'commerce_document_events';

        // Filtering happens in SQL so the search covers the whole table, not just
        // the newest page. document_number lives inside the ciphertext and cannot
        // be matched here; it is matched in PHP against the current page only, and
        // the UI says so.
        $where = '';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = 'WHERE document_id LIKE %s OR document_type LIKE %s OR source_id LIKE %s';
            $params = [$like, $like, $like];
        }
        $sql = "SELECT d.document_id, d.document_type, d.source_id, d.snapshot, d.snapshot_cipher,
                       d.content_hash, d.superseded_by, d.created_at,
                       (SELECT COUNT(*) FROM {$events} e WHERE e.document_id = d.document_id) AS audit_count
                FROM {$table} d {$where} ORDER BY d.id DESC LIMIT 50";
        $rows = $params === []
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $documents = [];
        foreach ((array) $rows as $row) {
            // One unreadable row must not take down the whole screen.
            try {
                $snapshot = WpdbDocumentRepository::hydrate($row, self::codec());
                $row['document_number'] = $snapshot === null
                    ? ''
                    : (string) $snapshot->toArray()['document_number'];
                $row['readable'] = $snapshot !== null;
            } catch (Throwable $error) {
                $row['document_number'] = '';
                $row['readable'] = false;
            }
            $row['audit_count'] = (int) ($row['audit_count'] ?? 0);
            unset($row['snapshot'], $row['snapshot_cipher'], $row['content_hash']);
            $documents[] = $row;
        }
        return $documents;
    }

    private static function find(string $documentId): ?DocumentSnapshot
    {
        global $wpdb;
        if (!is_object($wpdb) || $documentId === '') {
            return null;
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT document_id, snapshot, snapshot_cipher, content_hash, superseded_by, superseded_at
             FROM {$table} WHERE document_id = %s LIMIT 1",
            $documentId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        // Single hydration path: encrypted rows are authenticated by AES-GCM and
        // legacy plaintext rows by content_hash. Neither is trusted unverified.
        return WpdbDocumentRepository::hydrate($row, self::codec());
    }

    /** @return array{superseded_by:string,superseded_at:string} */
    private static function supersession(string $documentId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT superseded_by, superseded_at FROM {$wpdb->prefix}commerce_documents
             WHERE document_id = %s LIMIT 1",
            $documentId
        ), ARRAY_A);
        return [
            'superseded_by' => (string) ($row['superseded_by'] ?? ''),
            'superseded_at' => (string) ($row['superseded_at'] ?? ''),
        ];
    }

    private static function events(string $documentId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'commerce_document_events';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT event_name, created_at, event_hash FROM {$table} WHERE document_id = %s ORDER BY id ASC",
            $documentId
        ), ARRAY_A);
    }

    private static function codec(): EncryptedSnapshotCodec
    {
        return new EncryptedSnapshotCodec(new OpenSslAesGcmCipher(ConfigKeyProvider::encryptionKey()));
    }

    private static function summaryCard(string $label, string $value, string $detail): string
    {
        return '<div class="cdk-summary__card"><span>' . esc_html($label) . '</span><strong>'
            . esc_html($value) . '</strong><small>' . esc_html($detail) . '</small></div>';
    }

    private static function styles(): void
    {
        echo '<style>
        .cdk-admin{max-width:1240px}.cdk-admin h1,.cdk-admin h2,.cdk-admin h3{margin-top:0;color:#172033}.cdk-admin h2{font-size:20px}.cdk-admin h3{font-size:15px;margin-bottom:8px}.cdk-hero{display:flex;gap:24px;justify-content:space-between;align-items:center;margin:18px 0 16px;padding:25px 28px;border-radius:12px;background:linear-gradient(120deg,#0f2744,#123d66);color:#fff}.cdk-hero h1{margin:2px 0 7px;color:#fff;font-size:28px}.cdk-hero p{margin:0;color:#d7e8f7}.cdk-eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:11px;font-weight:700}.cdk-status{padding:8px 11px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}.cdk-status.is-enabled{background:#d8f3e5;color:#075a31}.cdk-status.is-disabled{background:#fff0d8;color:#8a4b00}.cdk-callout{margin:0 0 18px;padding:13px 16px;border-left:4px solid #00a8a8;background:#edf8f8;color:#24404a}.cdk-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}.cdk-summary__card,.cdk-card{background:#fff;border:1px solid #dbe3ea;border-radius:10px;box-shadow:0 1px 2px rgba(15,39,68,.04)}.cdk-summary__card{padding:15px}.cdk-summary__card span,.cdk-summary__card small{display:block;color:#667085;font-size:12px}.cdk-summary__card strong{display:block;margin:7px 0;color:#172033;font-size:21px}.cdk-card{padding:22px;margin:0 0 18px}.cdk-card__head{display:flex;gap:20px;justify-content:space-between;align-items:flex-start;margin-bottom:18px}.cdk-card__head p{margin:4px 0 0;color:#667085}.cdk-choice-row{display:flex;gap:20px;flex-wrap:wrap}.cdk-choice-row br{display:none}.cdk-divider{border:0;border-top:1px solid #e5e7eb;margin:24px 0}.cdk-status-table{max-width:760px;margin:14px 0}.cdk-quick-actions{background:linear-gradient(180deg,#fff,#f8fbfd)}.cdk-action-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.cdk-action-grid>div{padding:17px;border:1px solid #dbe7ef;border-radius:8px;background:#fff}.cdk-action-grid p{min-height:36px;color:#526172}.cdk-action-grid label{font-weight:600;margin-right:8px}.cdk-action-grid input[type=number]{width:104px}.cdk-search{display:flex;gap:8px;align-items:center}.cdk-search input{min-width:280px}.cdk-migration{margin:0 0 16px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:7px;background:#f8fafc}.cdk-migration summary{cursor:pointer;font-weight:600;color:#334155}.cdk-migration[open] summary{margin-bottom:12px}.cdk-table-wrap{overflow-x:auto}.cdk-documents th{white-space:nowrap}.cdk-documents td{vertical-align:top}.cdk-badge{display:inline-block;padding:3px 7px;border-radius:99px;font-size:11px;font-weight:700}.cdk-badge--issued{background:#ddf7e6;color:#086236}.cdk-badge--replaced{background:#fff0d8;color:#8a4b00}.cdk-badge--unreadable{background:#fde2e1;color:#a12622}.cdk-documents td small{display:block;margin-top:4px;color:#667085;word-break:break-all}.cdk-audit{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:50%;background:#edf2f7;font-weight:700}.cdk-actions{min-width:280px}.cdk-actions form{display:inline-block;margin:0 0 6px 6px}.cdk-actions input[type=text]{max-width:155px}.cdk-empty{padding:24px;border:1px dashed #b7c7d5;border-radius:8px;text-align:center;color:#526172}@media(max-width:782px){.cdk-hero,.cdk-card__head{align-items:flex-start;flex-direction:column}.cdk-summary,.cdk-action-grid{grid-template-columns:1fr}.cdk-search{width:100%;flex-wrap:wrap}.cdk-search input{width:100%;min-width:0}.cdk-actions{min-width:250px}}
        </style>';
        echo '<style>
        .cdk-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:4px;background:#f5f8fb;border:1px solid #dbe3ea;border-radius:10px;width:max-content;max-width:100%}.cdk-nav a{display:inline-flex;align-items:center;min-height:34px;padding:0 13px;border-radius:7px;color:#17324d;text-decoration:none;font-weight:600}.cdk-nav a:hover,.cdk-nav a:focus{background:#e5f5f5;color:#007f7c;box-shadow:0 0 0 2px rgba(0,168,168,.18)}.cdk-card[id]{scroll-margin-top:20px}.cdk-action-stack{display:flex;flex-wrap:wrap;align-items:center;gap:6px;min-width:300px}.cdk-action-stack>.button,.cdk-action-stack .cdk-inline-form .button,.cdk-action-stack .cdk-correction-form .button{min-width:92px;min-height:32px;box-sizing:border-box;text-align:center}.cdk-action-stack .cdk-inline-form{display:inline-flex;gap:6px;align-items:center;margin:0!important}.cdk-action-stack .cdk-correction-form{display:grid!important;grid-template-columns:minmax(105px,1fr) minmax(150px,1fr) auto;gap:6px;align-items:center;margin:6px 0 0!important;width:100%}.cdk-action-stack .cdk-correction-form input{min-width:0;width:100%}.cdk-action-grid form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}@media(max-width:782px){.cdk-nav{width:auto}.cdk-action-stack{min-width:0}.cdk-action-stack .cdk-correction-form{grid-template-columns:1fr}.cdk-action-stack .cdk-correction-form .button{width:100%}}
        .cdk-hero__tools{display:flex;flex-direction:column;align-items:flex-end;gap:10px}.cdk-language-switcher{display:flex;align-items:center;gap:7px;padding:6px 8px;border-radius:8px;background:rgba(255,255,255,.12);color:#fff}.cdk-language-switcher label{font-size:12px;font-weight:600}.cdk-language-switcher select{min-height:30px}.cdk-language-switcher .button{min-height:30px;padding:0 10px}@media(max-width:782px){.cdk-hero__tools{align-items:flex-start;width:100%}.cdk-language-switcher{flex-wrap:wrap}}
        </style>';
    }

    private static function field(
        string $label,
        string $key,
        string $value,
        string $type = 'text',
        bool $readOnly = false
    ): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" type="' . esc_attr($type)
            . '" name="commerce_documents_wc_settings[seller][' . esc_attr($key) . ']" value="' . esc_attr($value) . '"'
            . ($key === 'tax_identifier' ? '' : ' data-cdk-seller-source-field="1"')
            . ($readOnly ? ' readonly' : '') . '></td></tr>';
    }

    private static function addressField(string $label, string $key, string $value, bool $readOnly = false): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" type="text" name="commerce_documents_wc_settings[seller][address]['
            . esc_attr($key) . ']" value="' . esc_attr($value) . '"'
            . ' data-cdk-seller-source-field="1"'
            . ($readOnly ? ' readonly' : '') . '></td></tr>';
    }

    private static function sellerSourceScript(): void
    {
        echo '<script>(function(){'
            . 'var radios=document.querySelectorAll("input[name=\"commerce_documents_wc_settings[seller_source]\"]");'
            . 'var fields=document.querySelectorAll("[data-cdk-seller-source-field]");'
            . 'function sync(){var manual=false;for(var i=0;i<radios.length;i++){if(radios[i].checked&&radios[i].value==="manual"){manual=true;break;}}for(var j=0;j<fields.length;j++){fields[j].readOnly=!manual;}}'
            . 'for(var i=0;i<radios.length;i++){radios[i].addEventListener("change",sync);}sync();'
            . '})();</script>';
    }

    private static function notice(): void
    {
        $result = isset($_GET['cdk_result']) ? sanitize_key((string) $_GET['cdk_result']) : '';
        if ($result === 'generated') {
            echo '<div class="notice notice-success"><p>' . esc_html(self::t('generated')) . '</p></div>';
        } elseif ($result === 'sandbox_sent') {
            echo '<div class="notice notice-success"><p>' . esc_html(self::t('sandbox_created')) . '</p></div>';
        } elseif ($result !== '') {
            $message = isset($_GET['cdk_message']) ? sanitize_text_field(wp_unslash($_GET['cdk_message'])) : '';
            echo '<div class="notice notice-error"><p>' . esc_html($message !== '' ? $message : self::t('operation_failed')) . '</p></div>';
        }
    }
}
