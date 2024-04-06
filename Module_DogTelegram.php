<?php
namespace GDO\DogTelegram;

use GDO\Core\Application;
use GDO\Core\GDO_DBException;
use GDO\Core\GDO_Module;
use GDO\Core\GDT_Name;
use GDO\Core\GDT_Secret;
use GDO\Dog\DOG_Connector;
use GDO\Dog\DOG_Server;
use GDO\Dog\DOG_User;
use GDO\DogTelegram\Connector\Telegram;
use GDO\Subscription\GDT_SubscribeType;

final class Module_DogTelegram extends GDO_Module
{

    public function getDependencies(): array
    {
        return [
            'Dog',
            'Subscription',
        ];
    }

    public function getConfig(): array
    {
        $secret = @include $this->filePath('secret.php');
        $key = $secret['api_key'] ?? null;
        $name = $secret['bot_name'] ?? null;
        $user = $secret['bot_user'] ?? null;
        return [
            GDT_Secret::make('telegram_api_key')->initial($key),
            GDT_Name::make('telegram_bot_name')->initial($name),
            GDT_Name::make('telegram_bot_user')->initial($user),
        ];
    }

    public function cfgApiKey(): ?string { return $this->getConfigVar('telegram_api_key'); }
    public function cfgBotName(): ?string { return $this->getConfigVar('telegram_bot_name'); }
    public function cfgBotUser(): ?string { return $this->getConfigVar('telegram_bot_user'); }

    /**
     * @throws GDO_DBException
     */
    public function onInstall(): void
    {
        if (!($server = DOG_Server::getBy('serv_connector', 'Telegram')))
        {
            $server = DOG_Server::blank([
                'serv_connector' => 'Telegram',
                'serv_username' => $this->cfgBotUser(),
            ])->insert();
        }
        DOG_User::getOrCreateUser($server, $this->cfgBotUser(), $this->cfgBotName(), false);
    }

    public function onModuleInit(): void
    {
        if (Application::instance()->isCLI())
        {
            require_once $this->filePath('vendor/autoload.php');
        }
        GDT_SubscribeType::addSubscriptor($this);
        DOG_Connector::register(new Telegram());
    }

}
