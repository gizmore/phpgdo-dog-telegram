<?php

use GDO\CLI\Process;
use GDO\Core\Application;
use GDO\Core\Debug;
use GDO\Core\Logger;
use GDO\Core\ModuleLoader;
use GDO\DB\Database;
use GDO\DogTelegram\Module_DogTelegram;
use GDO\Dog\Module_Dog;
use Longman\TelegramBot\Entities\Update;

if (PHP_SAPI !== 'cli')
{
    echo "This can only be run from the command line.\n";
    die(-1);
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../../../protected/config.php';
require __DIR__ . '/../../../GDO7.php';

//CLI::init();
Debug::init();
Logger::init('dog_telegram', Logger::ALL, 'protected/logs_telegram');
Logger::disableBuffer();
Database::init();

final class dog_telegram extends Application
{

    public function isCLI(): bool
    {
        return true;
    }

}

$loader = ModuleLoader::instance();
$loader->loadModulesCache();
$mod = Module_DogTelegram::instance();
$api_key = $mod->cfgApiKey();
$bot_usr = $mod->cfgBotUser();
$telegram = new Longman\TelegramBot\Telegram($api_key, $bot_usr);
$telegram->useGetUpdatesWithoutDatabase();
$sleep = Module_Dog::instance()->cfgIdleSleepMicros();

while (true)
{
    $response = $telegram->handleGetUpdates();
    if ($response->isOk())
    {
        $result = $response->getResult();
        $count = 0;
        foreach ($result as $update)
        {
            /**
             * @var Update $update
             */
            $message = $update->getMessage();
            $message = $message ?: $update->getEditedMessage();
            if ($message)
            {
                $chat = $message->getChat();
                $user = $message->getFrom();
                printf("%s:%d:%s:%d:%s:%s:%s\n",
                    $chat->getType(),$chat->getId(), $chat->getTitle(),
                    $user->getId(), $user->getUsername(), $user->getLanguageCode(),
                    $message->getText());
            }
            else
            {
                print_r($update);
            }
            $count++;
        }
        if ($count === 0)
        {
            if (Process::isWindows())
            {
                echo "PING\n";
            }
        }
    }
    else
    {
//        print_r($response);
//        die();
    }
    usleep($sleep);
}
