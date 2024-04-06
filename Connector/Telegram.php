<?php
namespace GDO\DogTelegram\Connector;

use GDO\CLI\CLI;
use GDO\CLI\Process;
use GDO\Core\Application;
use GDO\Core\GDO_DBException;
use GDO\Core\GDT;
use GDO\Core\Logger;
use GDO\Dog\Dog;
use GDO\Dog\DOG_Connector;
use GDO\Dog\DOG_Message;
use GDO\Dog\DOG_Room;
use GDO\Dog\DOG_RoomUser;
use GDO\Dog\DOG_Server;
use GDO\Dog\DOG_User;
use GDO\DogTelegram\Module_DogTelegram;
use Longman\TelegramBot\Entities\ChatMember\ChatMemberMember;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 *
 */
final class Telegram extends DOG_Connector
{
    public $in = null;
    public $out = null;
    public $err = null;
    public $process = null;

    public \Longman\TelegramBot\Telegram $telegram;

    public function gdtRenderMode(): int
    {
        return GDT::RENDER_TELEGRAM;
    }

    public function getNickname(): string
    {
        return Module_DogTelegram::instance()->cfgBotUser();
    }


    /**
     * @throws TelegramException
     */
    public function init(): bool
    {
        $mod = Module_DogTelegram::instance();
        $api_key = $mod->cfgApiKey();
        $bot_usr = $mod->cfgBotUser();
        $this->telegram = new \Longman\TelegramBot\Telegram($api_key, $bot_usr);
        $this->telegram->useGetUpdatesWithoutDatabase();
        return true;
    }

    public function connect(): bool
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin is a pipe that the child will read from
            1 => ['pipe', 'w'],  // stdout is a pipe that the child will write to
            2 => ['pipe', 'w'],  // stderr is a pipe that the child will write to
        ];
        $env = [];
        $args = ['bypass_shell' => true];
        $pipes = [];
        $command = sprintf("php %s", escapeshellarg(GDO_PATH . 'GDO/DogTelegram/bin/dog_telegram.php') . '  ');
        if ($this->process = proc_open($command, $descriptorspec,$pipes, getcwd(), null, $args))
        {
            $this->in = $pipes[0];
            $this->out = $pipes[1];
            $this->err = $pipes[2];

            if (!Process::isWindows())
            {
                if (!stream_set_blocking($pipes[1], false))
                {
                    $this->disconnect("Cannot set process to non blocking.");
                    return false;
                }
            }
//            fclose($this->in);
//            $this->in = null;

//            if (#stream_set_blocking($pipes[0], 0) &&
//                stream_set_blocking($pipes[1], 0) &&
//                stream_set_blocking($pipes[2], 0))
//            {
                $this->connected(true);
                return true;
//            }
        }
        return false;
    }

    protected function onConnected(): void
    {
        $this->server->reloadRooms();
    }


    public function disconnect(string $reason): void
    {
        $this->running = false;
        if ($this->process)
        {
            fclose($this->in);
            fclose($this->out);
            fclose($this->err);
            proc_close($this->process);
            $this->process = $this->in = $this->out = $this->err = null;
        }
    }

    public function hasUserSubscribedRoom(DOG_User $user, DOG_Room $room): bool
    {
        static $checked = [];
        if (isset($checked[$user->getID()]))
        {
            return false;
        }
        $response = Request::getChatMember([
            'chat_id' => $room->getName(),
            'user_id' => $user->getName(),
        ]);
        if ($response->isOk())
        {
            /** @var ChatMemberMember $result */
            $result = $response->getResult();
            if ($result->getUser())
            {
                return true;
            }
        }
        return false;
    }


    /**
     * @throws GDO_DBException
     */
    public function readMessage(): bool
    {
        $read = [$this->out];
        $write = [];
        $error = [];
        if (stream_select($read, $write, $error, 0, 0) === 1)
        {
            return $this->readMessageB();
        }
        return false;
    }

    /**
     * @throws GDO_DBException
     */
    private function readMessageB(): bool
    {
//        while ($line = fgets($this->err))
//        {
//            Logger::logError('Telegram: '. $line);
//        }
        $line = fgets($this->out);
        $line = trim($line);

        if ($line === 'PING')
        {
            return false;
        }

        echo "Telegram << $line\n";

        $data = explode(':', $line, 7);

        if (count($data) === 7)
        {
            list($type, $chan_id, $chan_name, $user_id, $user_name, $lang_iso, $text) = $data;
            $user = DOG_User::getOrCreateUser($this->server, (string)$user_id, $user_name);
            $gdouser = $user->getGDOUser();
            $gdouser->saveSettingVar('Language', 'language', $lang_iso);
            $message = DOG_Message::make()->server($this->server)->user($user)->text(trim($text));
            if ($type !== 'private')
            {
                $room = DOG_Room::getOrCreate($this->server, $chan_id, '', '$', $chan_name);
                $message->room($room);
                $this->server->addRoom($room);
                $room->addUser($user);
                DOG_RoomUser::joined($user, $room);
            }
            Dog::instance()->event('dog_message', $message);
        }
        else
        {
            echo $line;
            return false;
        }
        return true;
    }

    public function setupServer(DOG_Server $server): void
    {
    }

    /**
     * @throws TelegramException
     */
    public function sendToUser(DOG_User $user, string $text): bool
    {
        parent::sendToUser($user, $text);

        echo "Telegram {$user->renderFullName()} >> {$text}\n";

//        $text = $this->escapeMarkdownV2($text);
        $response = Request::sendMessage([
            'chat_id' => $user->getName(),
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
        if (!$response->isOk())
        {
            Logger::logError(print_r($response, true));
        }
        return $response->isOk();
    }

    /**
     * @throws TelegramException
     */
    public function sendToRoom(DOG_Room $room, string $text): bool
    {
        echo "Telegram {$room->renderName()} >> {$text}\n";
        $response = Request::sendMessage([
            'chat_id' => $room->getName(),
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
        if (!$response->isOk())
        {
            Logger::logError(print_r($response, true));
        }
        parent::sendToRoom($room, $text);
        return $response->isOk();

    }

//    private function escapeMarkdownV2(string $text)
//    {
//        return str_replace(['!', '.'], ['\\!', '\\.'], $text);
//    }

    #############
    ### Style ###
    #############


//    public static function red(string $s): string { return $s; }
//
//    public static function green(string $s): string { return $s; }
//
//    public static function bold(string $s): string { return "**{$s}**"; }
//
//
//    public static function dim(string $s): string { return $s; }
//
//    public static function italic(string $s): string { return "__{$s}__"; }
//
//    public static function underlined(string $s): string { return $s; }
//
//    public static function blinking(string $s): string { return $s; }
//
//    public static function invisible(string $s): string { return $s; }

}
