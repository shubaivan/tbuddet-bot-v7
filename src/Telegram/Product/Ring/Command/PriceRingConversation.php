<?php

namespace App\Telegram\Product\Ring\Command;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Service\LocalizationService;
use App\Service\NovaPoshtaService;
use App\Service\ProductService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

class PriceRingConversation extends Conversation
{
    protected ?string $step = 'askParameters';

    // State
    public ?int $productId = null;
    public ?int $categoryId = null;
    public ?int $quantity = null;
    public ?bool $confirmPhone = false;

    // Single message ID — everything edits this one message
    public ?int $mainMessageId = null;
    public ?bool $mainMessageHasPhoto = false;

    // Product browsing
    public int $productPage = 0;
    public array $productIds = [];

    // Properties
    public array $selectedProperties = [];
    public array $propertyGroups = [];
    public int $currentPropertyGroupIndex = 0;

    // Delivery
    public ?string $deliveryCity = null;
    public ?string $deliveryCityRef = null;
    public ?string $deliveryDepartment = null;
    public ?string $deliveryDepartmentRef = null;
    public array $cityResults = [];
    public array $warehouseResults = [];

    public function __construct(
        private LocalizationService $localizationService,
        private FilesystemOperator $defaultStorage,
        private TelegramUserService $telegramUserService,
        private ProductService $productService,
        private NovaPoshtaService $novaPoshtaService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
        private string $projectDir
    ) {
        $this->confirmPhone = false;
    }

    private function lang(): UserLanguageEnum
    {
        return $this->localizationService->getLanguage(
            $this->telegramUserService->getCurrentUser()->getLanguageCode()
        );
    }

    // ─── Helper: get photo URL (skip avif) ───

    private function getProductPhotoUrl($product): ?string
    {
        $files = $product->getFiles();
        if ($files->count() === 0) return null;

        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                return $this->defaultStorage->publicUrl($file->getPath());
            }
        }
        return null;
    }

    // ─── Helper: send or edit the single main message ───

    private function sendOrEdit(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null, ?string $photoUrl = null): void
    {
        $chatId = $bot->chatId();

        // Try to edit existing message
        if ($this->mainMessageId) {
            try {
                if ($photoUrl && $this->mainMessageHasPhoto) {
                    // Edit photo message
                    $bot->editMessageMedia(
                        media: new InputMediaPhoto(
                            media: $photoUrl,
                            caption: $text,
                            parse_mode: ParseMode::HTML,
                        ),
                        chat_id: $chatId,
                        message_id: $this->mainMessageId,
                        reply_markup: $keyboard,
                    );
                    return;
                } elseif (!$photoUrl && !$this->mainMessageHasPhoto) {
                    // Edit text message
                    $bot->editMessageText(
                        text: $text,
                        chat_id: $chatId,
                        message_id: $this->mainMessageId,
                        parse_mode: ParseMode::HTML,
                        reply_markup: $keyboard,
                    );
                    return;
                } else {
                    // Type changed (photo ↔ text) — delete old and send new
                    try {
                        $bot->deleteMessage($chatId, $this->mainMessageId);
                    } catch (\Throwable $e) {}
                    $this->mainMessageId = null;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to edit message', ['error' => $e->getMessage()]);
                // Delete failed message and send new
                try {
                    $bot->deleteMessage($chatId, $this->mainMessageId);
                } catch (\Throwable $e2) {}
                $this->mainMessageId = null;
            }
        }

        // Send new message
        if ($photoUrl) {
            try {
                $msg = $bot->sendPhoto(
                    photo: $photoUrl,
                    caption: $text,
                    parse_mode: ParseMode::HTML,
                    reply_markup: $keyboard,
                );
                $this->mainMessageId = $msg->message_id;
                $this->mainMessageHasPhoto = true;
                return;
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send photo', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: text only
        $msg = $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: $keyboard,
        );
        $this->mainMessageId = $msg->message_id;
        $this->mainMessageHasPhoto = false;
    }

    // ─── Build order info text (accumulates as steps progress) ───

    private function buildInfoText(?string $currentStep = null, ?string $stepPrompt = null): string
    {
        $product = $this->productId ? $this->productService->getProduct($this->productId) : null;
        $lines = [];

        if ($product) {
            $lines[] = sprintf("📦 <b>%s</b> — %s грн",
                $product->getProductName($this->lang()),
                $product->getPrice($this->lang())
            );
        }

        foreach ($this->selectedProperties as $prop) {
            $line = sprintf("✅ %s: %s", $prop['property_name'], $prop['property_value']);
            if ($prop['property_price_impact'] != 0) {
                $line .= sprintf(' (%+d грн)', $prop['property_price_impact']);
            }
            $lines[] = $line;
        }

        if ($this->deliveryCity) {
            $lines[] = sprintf("📍 %s", $this->deliveryCity);
        }
        if ($this->deliveryDepartment) {
            $lines[] = sprintf("📬 %s", $this->deliveryDepartment);
        }
        if ($this->quantity) {
            $lines[] = sprintf("🔢 Кількість: %d", $this->quantity);

            if ($product) {
                $basePrice = $product->getPrice($this->lang());
                $impacts = array_sum(array_column($this->selectedProperties, 'property_price_impact'));
                $total = ($basePrice + $impacts) * $this->quantity;
                $lines[] = sprintf("\n💰 <b>Сума: %s грн</b>", $total);
            }
        }

        if ($stepPrompt) {
            $lines[] = '';
            $lines[] = $stepPrompt;
        }

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════
    // STEP 1: Categories
    // ═══════════════════════════════════════════

    public function askParameters(Nutgram $bot)
    {
        $this->mainMessageId = null;
        $this->mainMessageHasPhoto = false;

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($this->productService->getCategories() as $categorySet) {
            if ($categorySet->getTotalProduct() === 0) continue;
            $name = $categorySet->getCategory()->getCategoryName($this->lang());
            $count = $categorySet->getTotalProduct();
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    sprintf('%s (%d)', $name, $count),
                    callback_data: 'category_' . $categorySet->getCategory()->getId()
                )
            );
        }

        $this->sendOrEdit($bot, '📦 <b>Оберіть категорію:</b>', $keyboard);
        $this->next('askCategory');
    }

    // ═══════════════════════════════════════════
    // STEP 2: Product carousel (one at a time, edit in place)
    // ═══════════════════════════════════════════

    public function askCategory(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_contains($bot->callbackQuery()->data, 'category_')) {
            $this->askParameters($bot);
            return;
        }
        $this->categoryId = (int)str_replace('category_', '', $bot->callbackQuery()->data);
        $this->productPage = 0;
        $this->productIds = [];

        foreach ($this->productService->getProductsForBot($this->categoryId) as $products) {
            foreach ($products as $product) {
                $this->productIds[] = $product->getId();
            }
        }

        $this->showProduct($bot);
        $this->next('handleProductBrowse');
    }

    private function showProduct(Nutgram $bot): void
    {
        $productId = $this->productIds[$this->productPage] ?? $this->productIds[0];
        $product = $this->productService->getProduct($productId);
        $total = count($this->productIds);

        $text = sprintf(
            "📦 <b>%s</b>\n💰 %s грн\n\n%d / %d",
            $product->getProductName($this->lang()),
            $product->getPrice($this->lang()),
            $this->productPage + 1,
            $total
        );

        $keyboard = InlineKeyboardMarkup::make();
        $keyboard->addRow(
            InlineKeyboardButton::make('🛒 Обрати', callback_data: 'product_' . $product->getId())
        );

        $navRow = [];
        if ($this->productPage > 0) {
            $navRow[] = InlineKeyboardButton::make('◀️', callback_data: 'page_prev');
        }
        $navRow[] = InlineKeyboardButton::make('🔙 Категорії', callback_data: 'back_categories');
        if ($this->productPage < $total - 1) {
            $navRow[] = InlineKeyboardButton::make('▶️', callback_data: 'page_next');
        }
        $keyboard->addRow(...$navRow);

        $photoUrl = $this->getProductPhotoUrl($product);
        $this->sendOrEdit($bot, $text, $keyboard, $photoUrl);
    }

    public function handleProductBrowse(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->showProduct($bot);
            $this->next('handleProductBrowse');
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === 'page_next') {
            $this->productPage = min($this->productPage + 1, count($this->productIds) - 1);
            $this->showProduct($bot);
            $this->next('handleProductBrowse');
            return;
        }
        if ($data === 'page_prev') {
            $this->productPage = max(0, $this->productPage - 1);
            $this->showProduct($bot);
            $this->next('handleProductBrowse');
            return;
        }
        if ($data === 'back_categories') {
            $this->askParameters($bot);
            return;
        }
        if (str_contains($data, 'product_')) {
            $this->selectProduct($bot);
            return;
        }

        $this->showProduct($bot);
        $this->next('handleProductBrowse');
    }

    // ═══════════════════════════════════════════
    // STEP 3: Product selected → start property selection
    // ═══════════════════════════════════════════

    private function selectProduct(Nutgram $bot): void
    {
        $this->productId = (int)str_replace('product_', '', $bot->callbackQuery()->data);
        $this->selectedProperties = [];
        $this->currentPropertyGroupIndex = 0;
        $this->quantity = null;
        $this->deliveryCity = null;
        $this->deliveryCityRef = null;
        $this->deliveryDepartment = null;
        $this->deliveryDepartmentRef = null;

        $product = $this->productService->getProduct($this->productId);
        $properties = $product->getProductProperties($this->lang());
        $this->propertyGroups = [];

        $grouped = [];
        foreach ($properties as $property) {
            $name = trim($property['property_name'] ?? '');
            if ($name === '') continue;
            $value = trim($property['property_value'] ?? '');
            if ($value === '') continue;
            $grouped[$name][] = [
                'property_value' => $property['property_value'],
                'property_price_impact' => (int)($property['property_price_impact'] ?? 0),
            ];
        }
        $this->propertyGroups = array_map(
            fn($name, $values) => ['property_name' => $name, 'values' => $values],
            array_keys($grouped),
            array_values($grouped)
        );

        if (count($this->propertyGroups) > 0) {
            $this->autoSelectOrShowProperty($bot);
        } else {
            $this->askCityText($bot);
        }
    }

    // ═══════════════════════════════════════════
    // STEP 4: Property selection (edit same message each time)
    // ═══════════════════════════════════════════

    private function autoSelectOrShowProperty(Nutgram $bot): void
    {
        // Auto-select properties with only one value
        while ($this->currentPropertyGroupIndex < count($this->propertyGroups)) {
            $group = $this->propertyGroups[$this->currentPropertyGroupIndex];
            if (count($group['values']) === 1) {
                $this->selectedProperties[] = [
                    'property_name' => $group['property_name'],
                    'property_value' => $group['values'][0]['property_value'],
                    'property_price_impact' => $group['values'][0]['property_price_impact'],
                ];
                $this->currentPropertyGroupIndex++;
            } else {
                break;
            }
        }

        if ($this->currentPropertyGroupIndex < count($this->propertyGroups)) {
            $this->showPropertyStep($bot);
            $this->next('handlePropertySelection');
        } else {
            $this->askCityText($bot);
        }
    }

    private function showPropertyStep(Nutgram $bot): void
    {
        $group = $this->propertyGroups[$this->currentPropertyGroupIndex];
        $prompt = sprintf('🔧 <b>Оберіть %s:</b>', htmlspecialchars($group['property_name']));

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($group['values'] as $index => $value) {
            $label = $value['property_value'];
            if ($value['property_price_impact'] > 0) {
                $label .= sprintf(' (+%s грн)', $value['property_price_impact']);
            } elseif ($value['property_price_impact'] < 0) {
                $label .= sprintf(' (%s грн)', $value['property_price_impact']);
            }
            $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'prop_' . $this->currentPropertyGroupIndex . '_' . $index)
            );
        }

        $text = $this->buildInfoText(stepPrompt: $prompt);
        $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
        $this->sendOrEdit($bot, $text, $keyboard, $photoUrl);
    }

    public function handlePropertySelection(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_starts_with($bot->callbackQuery()->data, 'prop_')) {
            $this->showPropertyStep($bot);
            $this->next('handlePropertySelection');
            return;
        }

        $parts = explode('_', $bot->callbackQuery()->data);
        $groupIndex = (int)$parts[1];
        $valueIndex = (int)$parts[2];

        $group = $this->propertyGroups[$groupIndex];
        $selectedValue = $group['values'][$valueIndex];

        $this->selectedProperties[] = [
            'property_name' => $group['property_name'],
            'property_value' => $selectedValue['property_value'],
            'property_price_impact' => $selectedValue['property_price_impact'],
        ];

        $this->currentPropertyGroupIndex++;
        $this->autoSelectOrShowProperty($bot);
    }

    // ═══════════════════════════════════════════
    // STEP 5: Nova Poshta city search
    // ═══════════════════════════════════════════

    private function askCityText(Nutgram $bot): void
    {
        $text = $this->buildInfoText(stepPrompt: '📍 <b>Введіть назву міста</b> (Нова Пошта):');
        $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
        $this->sendOrEdit($bot, $text, null, $photoUrl);
        $this->next('handleCityInput');
    }

    public function handleCityInput(Nutgram $bot)
    {
        if ($bot->isCallbackQuery()) {
            $this->askCityText($bot);
            return;
        }

        $cityQuery = trim($bot->message()->text ?? '');

        // Delete user's text message to keep chat clean
        try { $bot->deleteMessage($bot->chatId(), $bot->message()->message_id); } catch (\Throwable $e) {}

        if (strlen($cityQuery) < 2) {
            $this->askCityText($bot);
            return;
        }

        $cities = $this->novaPoshtaService->searchCities($cityQuery, 8);

        if (empty($cities)) {
            $text = $this->buildInfoText(stepPrompt: '❌ Місто не знайдено. Введіть назву міста:');
            $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
            $this->sendOrEdit($bot, $text, null, $photoUrl);
            $this->next('handleCityInput');
            return;
        }

        $this->cityResults = [];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($cities as $index => $city) {
            $desc = $city['Description'] ?? '';
            $area = $city['AreaDescription'] ?? '';
            $label = $area ? sprintf('%s (%s обл.)', $desc, $area) : $desc;
            $this->cityResults[$index] = ['ref' => $city['Ref'] ?? '', 'description' => $desc];
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: 'city_' . $index));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 Шукати інше місто', callback_data: 'retry_city'));

        $text = $this->buildInfoText(stepPrompt: '🏙 <b>Оберіть місто:</b>');
        $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
        $this->sendOrEdit($bot, $text, $keyboard, $photoUrl);
        $this->next('handleCitySelection');
    }

    public function handleCitySelection(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            // User typed text — treat as new city search
            $this->handleCityInput($bot);
            return;
        }

        if ($bot->callbackQuery()->data === 'retry_city') {
            $this->askCityText($bot);
            return;
        }

        if (!str_starts_with($bot->callbackQuery()->data, 'city_')) {
            $this->askCityText($bot);
            return;
        }

        $cityIndex = (int)str_replace('city_', '', $bot->callbackQuery()->data);
        $city = $this->cityResults[$cityIndex] ?? null;
        if (!$city) {
            $this->askCityText($bot);
            return;
        }

        $this->deliveryCity = $city['description'];
        $this->deliveryCityRef = $city['ref'];

        // Load warehouses
        $warehouses = $this->novaPoshtaService->getWarehouses($this->deliveryCityRef, 30);

        if (empty($warehouses)) {
            $this->deliveryCity = null;
            $this->deliveryCityRef = null;
            $text = $this->buildInfoText(stepPrompt: '❌ Відділення не знайдено. Введіть інше місто:');
            $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
            $this->sendOrEdit($bot, $text, null, $photoUrl);
            $this->next('handleCityInput');
            return;
        }

        $this->warehouseResults = [];
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($warehouses as $index => $wh) {
            $desc = $wh['Description'] ?? '';
            $this->warehouseResults[$index] = [
                'ref' => $wh['Ref'] ?? '',
                'description' => $desc,
            ];
            $label = mb_strlen($desc) > 45 ? mb_substr($desc, 0, 42) . '...' : $desc;
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: 'wh_' . $index));
        }

        $text = $this->buildInfoText(stepPrompt: '📬 <b>Оберіть відділення:</b>');
        $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
        $this->sendOrEdit($bot, $text, $keyboard, $photoUrl);
        $this->next('handleWarehouseSelection');
    }

    // ═══════════════════════════════════════════
    // STEP 6: Warehouse selection
    // ═══════════════════════════════════════════

    public function handleWarehouseSelection(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_starts_with($bot->callbackQuery()->data, 'wh_')) {
            return;
        }

        $whIndex = (int)str_replace('wh_', '', $bot->callbackQuery()->data);
        $warehouse = $this->warehouseResults[$whIndex] ?? null;
        if (!$warehouse) return;

        $this->deliveryDepartment = $warehouse['description'];
        $this->deliveryDepartmentRef = $warehouse['ref'];

        // Ask quantity
        $text = $this->buildInfoText(stepPrompt: '🔢 <b>Введіть кількість:</b>');
        $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
        $this->sendOrEdit($bot, $text, null, $photoUrl);
        $this->next('handleQuantity');
    }

    // ═══════════════════════════════════════════
    // STEP 7: Quantity
    // ═══════════════════════════════════════════

    public function handleQuantity(Nutgram $bot)
    {
        if ($bot->isCallbackQuery()) {
            return;
        }

        $text = $bot->message()->text ?? '';
        try { $bot->deleteMessage($bot->chatId(), $bot->message()->message_id); } catch (\Throwable $e) {}

        if (!preg_match('/^[0-9]+$/', $text) || (int)$text < 1) {
            $infoText = $this->buildInfoText(stepPrompt: '❌ Тільки цифри. Введіть кількість:');
            $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
            $this->sendOrEdit($bot, $infoText, null, $photoUrl);
            $this->next('handleQuantity');
            return;
        }

        $this->quantity = (int)$text;

        // Show final summary with confirm buttons
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✅ Оформити', callback_data: 'confirm'),
            InlineKeyboardButton::make('❌ Скасувати', callback_data: 'cancel'),
        );

        $infoText = $this->buildInfoText(stepPrompt: "\n<b>Підтвердіть замовлення:</b>");
        $photoUrl = $this->getProductPhotoUrl($this->productService->getProduct($this->productId));
        $this->sendOrEdit($bot, $infoText, $keyboard, $photoUrl);

        $this->next('handleConfirm');
    }

    // ═══════════════════════════════════════════
    // STEP 8: Confirm → phone check → payment
    // ═══════════════════════════════════════════

    public function handleConfirm(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) return;

        if ($bot->callbackQuery()->data === 'cancel') {
            $this->askParameters($bot);
            return;
        }

        if ($bot->callbackQuery()->data !== 'confirm') return;

        // Check phone
        if (!$this->telegramUserService->getCurrentUser()->getPhoneNumber()) {
            $this->confirmPhone = true;
            $bot->sendMessage(
                text: '📱 Підтвердіть номер телефону:',
                reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                    KeyboardButton::make('📱 Підтвердити телефон', true),
                )
            );
            $this->next('handlePhone');
            return;
        }

        $this->createOrder($bot);
    }

    public function handlePhone(Nutgram $bot)
    {
        if ($bot->message() && $bot->message()->contact && $bot->message()->contact->phone_number) {
            $phone = $bot->message()->contact->phone_number;
            $bot->sendMessage(
                text: '...',
                reply_markup: ReplyKeyboardRemove::make(true),
            )?->delete();
            $this->telegramUserService->savePhone($phone);
            $this->em->flush();
            $this->createOrder($bot);
            return;
        }

        $bot->sendMessage(
            text: '📱 Натисніть кнопку нижче:',
            reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                KeyboardButton::make('📱 Підтвердити телефон', true),
            )
        );
        $this->next('handlePhone');
    }

    // ═══════════════════════════════════════════
    // STEP 9: Create order + LiqPay
    // ═══════════════════════════════════════════

    private function createOrder(Nutgram $bot): void
    {
        $language = $this->lang();
        $product = $this->productService->getProduct($this->productId);

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setTelegramUserid($this->telegramUserService->getCurrentUser());
        $userOrder->setProductProperties($this->selectedProperties);
        $userOrder->setDeliveryCity($this->deliveryCity);
        $userOrder->setDeliveryCityRef($this->deliveryCityRef);
        $userOrder->setDeliveryDepartment($this->deliveryDepartment);
        $userOrder->setDeliveryDepartmentRef($this->deliveryDepartmentRef);

        $basePrice = $product->getPrice($language);
        $impacts = array_sum(array_column($this->selectedProperties, 'property_price_impact'));
        $totalAmount = ($basePrice + $impacts) * $this->quantity;

        $userOrder->setQuantityProduct($language, $this->quantity);
        $userOrder->setTotalAmount($totalAmount);

        $description = sprintf('Замовлення: %s × %s', $product->getProductName($language), $this->quantity);
        foreach ($this->selectedProperties as $prop) {
            $description .= sprintf(', %s: %s', $prop['property_name'], $prop['property_value']);
        }
        if ($this->deliveryCity) {
            $description .= sprintf('. Доставка: %s, %s', $this->deliveryCity, $this->deliveryDepartment ?? '');
        }

        $userOrder->setDescription($description);
        $this->em->persist($userOrder);
        $this->em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());
        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        $params = [
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $userOrder->getTelegramUserid()->getPhoneNumber(),
            'amount' => $totalAmount,
            'currency' => $language === UserLanguageEnum::UA ? 'UAH' : 'USD',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description,
        ];
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $params['action'] = 'pay';
        $cnb_form_raw = $liqpay->cnb_form_raw($params);
        $link = sprintf('%s?data=%s&signature=%s',
            $cnb_form_raw['url'],
            $cnb_form_raw['data'],
            $cnb_form_raw['signature'],
        );

        // Update main message with final info + payment link
        $finalText = $this->buildInfoText();
        $finalText .= sprintf("\n\n🔗 <a href=\"%s\">Перейти до оплати</a>", $link);
        $finalText .= "\n\n🎉 <b>Дякуємо!</b> Чекайте повідомлення.";

        $photoUrl = $this->getProductPhotoUrl($product);
        $this->sendOrEdit($bot, $finalText, null, $photoUrl);

        $this->end();
    }
}
