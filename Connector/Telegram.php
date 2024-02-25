<?php
namespace GDO\DogTelegram\Connector;

use GDO\Core\GDT;
use GDO\Dog\Dog;
use GDO\Dog\DOG_Connector;
use GDO\Dog\DOG_Message;
use GDO\Dog\DOG_Room;
use GDO\Dog\DOG_Server;
use GDO\Dog\DOG_User;
use GDO\DogTelegram\Module_DogTelegram;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

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
        $pipes = [];
        $command = sprintf("php %s", escapeshellarg(GDO_PATH . 'GDO/DogTelegram/bin/dog_telegram.php'));
        if ($this->process = proc_open($command, $descriptorspec,$pipes))
        {
            $this->in = $pipes[0];
            $this->out = $pipes[1];
            $this->err = $pipes[2];
            $this->connected(true);
            return true;
        }
        return false;
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

    public function readMessage(): ?DOG_Message
    {
        if (!($line = fgets($this->out)))
        {
            return null;
        }
        $data = explode(':', $line, 5);

        if (count($data) === 5)
        {
            list($type, $chan_id, $user_id, $user_name, $text) = $data;
            $user = DOG_User::getOrCreateUser($this->server, (string)$user_id, $user_name);
            $message = DOG_Message::make()->server($this->server)->user($user)->text(trim($text));
            if ($type !== 'private')
            {
                $room = DOG_Room::getOrCreate($this->server, (string)$chan_id);
                $message->room($room);
            }

            Dog::instance()->event('dog_message', $message);

            return $message;
        }
        echo $line;
        return null;
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
//        $text = $this->escapeMarkdownV2($text);
        $response = Request::sendMessage([
            'chat_id' => $user->getName(),
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
        if (!$response->isOk())
        {
            print_r($response);
        }
        return $response->isOk();
    }

//    private function escapeMarkdownV2(string $text)
//    {
//        return str_replace(['!', '.'], ['\\!', '\\.'], $text);
//    }

    #############
    ### Style ###
    #############


    public static function red(string $s): string { return $s; }

    public static function green(string $s): string { return $s; }

    public static function bold(string $s): string { return "**{$s}**"; }


    public static function dim(string $s): string { return $s; }

    public static function italic(string $s): string { return "__{$s}__"; }

    public static function underlined(string $s): string { return $s; }

    public static function blinking(string $s): string { return $s; }

    public static function invisible(string $s): string { return $s; }

}
