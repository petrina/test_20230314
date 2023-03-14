<?php

class Request {

    protected stdClass $query;
    protected stdClass $body;

    public function __construct() {
        $this->query = (object)$_GET;
        $this->body = (object)$_POST;
    }

    private function get(string $type, string $key): mixed {
        if (isset($this->{$type}->{$key})) {
            return $this->{$type}->{$key};
        } else {
            return null;
        }
    }

    public function getQuery(string $key): mixed {
        return $this->get('query', $key);
    }

    public function getBody(string $key): mixed {
        return $this->get('body', $key);
    }

    public function getQueryAll(): stdClass {
        return $this->query;
    }

    public function getBodyAll(): stdClass {
        return $this->body;
    }
}

interface Validate {
    public function check():bool;
}

class AbstractCheck{
    protected mixed $data;

    public function set(mixed $data) {
        $this->data = $data;
    }

}

class LengthValidate extends AbstractCheck implements Validate {

    protected int $min = 3;
    protected int $max = 30;

    public function check():bool {
        return strlen($this->data) >= $this->min && strlen($this->data) <= $this->max;
    }
}

class EmailValidate extends AbstractCheck implements Validate {

    public function check():bool {
        return is_numeric(strpos($this->data, '@'));
    }
}

class PasswordValidate extends LengthValidate implements Validate {

    protected int $min = 8;
    protected mixed $data2;

    public function set(mixed $data)
    {
        $this->data = reset($data);
        $this->data2 = end($data);
    }

    public function check():bool {
        return parent::check() && $this->data == $this->data2;
    }
}

class Json {
    protected int $status = 0;
    protected mixed $data;

    public function setStatus(int $status = 500):Json {
        $this->status = $status;
        return $this;
    }

    public function setData(mixed $data):Json {
        $this->data = $data;
        return $this;
    }

    public function response():void {
        $response = [
            'response' => true,
            'data' => $this->data,
        ];
        $this->show($response);
    }

    public function error():void {
        $response = [
            'response' => false,
            'error' => $this->data,
        ];
        $this->show($response);
    }

    private function show(array $data):void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($this->status);
        echo json_encode($data);
        exit;
    }

}

class Log {

    private ?Json $response;

    public function __construct(?Json $response) {
        $this->response = $response;
    }

    public function saveRequest(Request $request): void {
        $this->save(['get'=>$request->getQueryAll(),'post'=>$request->getBodyAll()]);
    }

    public function save(mixed $data):void {
        $str = date('Y-m-d H:i:s').' '.json_encode($data).PHP_EOL;
        $res = file_put_contents('./log.txt', $str, FILE_APPEND);
        if ($res === false) {
            $this->showError('Can\'t create log file');
        }
    }

    public function showError(string $message) {
        if ($this->response !== null) {
            $this->response->setData($message)->setStatus(500)->error();
        }
    }
}

class FileDb {

    private string $filename = '';
    private array $data = [];
    private ?Json $response;

    private const FIELDS = ['id', 'name', 'coname', 'email', 'password'];

    public function __construct(string $filename, ?Json $response) {
        $this->response = $response;
        $this->filename = $filename;
        $this->read();
    }

    public function read(): void {
        if (file_exists($this->filename)) {
            $dataFile = file($this->filename, FILE_IGNORE_NEW_LINES);
            foreach ($dataFile as $row) {
                $rowArr = explode(',', $row);
                if (count(self::FIELDS) === count($rowArr)) {
                    $record = (object)array_combine(self::FIELDS, $rowArr);
                    array_push($this->data, $record);
                }
            }
        }
    }

    public function select(string $key, string $value):?stdClass {
        $rowRes = null;
        foreach ($this->data as $row) {
            if (isset($row->{$key}) && $row->{$key} == $value) {
                $rowRes = $row;
                break;
            }
        }
        return ($rowRes !== null) ? (object) $rowRes : null;
    }

    public function insert(string $name, string $coname, string $email, string $password): void {
        if (count($this->data) === 0) {
            $nextId = 1;
        } else {
            $lastRow = end($this->data);
            $nextId = $lastRow->id + 1;
        }
        $this->data[] = (object)array_combine(self::FIELDS, [$nextId, $name, $coname, $email, $password]);
        $this->save();
    }

    private function save():void {
        $fp = fopen($this->filename, 'w');
        if ($fp === false) {
            $this->showError('Can\'t save data into file', 500);
        }
        $records = [];
        foreach ($this->data as $row) {
            $records[] = implode(',',(array)$row);
        }
        fwrite($fp, implode(PHP_EOL, $records));
        fclose($fp);
    }

    public function showError(string $message) {
        if ($this->response !== null) {
            $this->response->setData($message)->setStatus(500)->error();
        }
    }
}

class MainService {

    private Request $request;
    private Json $response;
    private Log $log;
    private FileDb $fileDb;

    const VALID_PARAMS = [
        'LengthValidate' => ['name', 'coname'],
        'EmailValidate' => ['mail'],
        'PasswordValidate' => [['pass', 'pass2']],
    ];

    public function __construct(Request $request, Json $json, Log $log, FileDb $fileDb) {
        $this->request = $request;
        $this->response = $json;
        $this->log = $log;
        $this->fileDb = $fileDb;
    }

    public function run():void {
        $this->log->saveRequest($this->request);
        $this->validate();
        if ($this->checkAnSave()) {
            $this->log->save('save user with email ' . $this->request->getBody('mail'));
            $this->response->setStatus(201)->setData('user was registered')->response();
        } else {
            $this->log->save('user with email '.$this->request->getBody('mail'). ' registered again');
            $this->response->setStatus(404)->setData('user with this email already registered')->error();
        }
    }

    public function validate(): void {
        foreach (self::VALID_PARAMS as $validClass => $params) {
            foreach ($params as $param) {
                if (!is_array($param)) {
                    $paramValue = $this->request->getBody($param);
                } else {
                    $paramValue = [];
                    foreach ($param as $value) {
                        $paramValue[] = $this->request->getBody($value);
                    }
                }
                $class = new $validClass();
                $class->set($paramValue);
                if (!$class->check()) {
                    $this->response->setStatus(400)->setData('Incorrect data '.implode(', ', $param))->error();
                }
            }
        }
    }

    public function checkAnSave(): bool {
        $existUser = $this->fileDb->select('email', $this->request->getBody('mail'));
        if ($existUser === null) {
            $this->fileDb->insert(
                $this->request->getBody('name'),
                $this->request->getBody('coname'),
                $this->request->getBody('mail'),
                $this->request->getBody('pass')
            );
            return true;
        }
        return false;
    }
}


$request = new Request();
$jsonResponse = new Json();
$log = new Log($jsonResponse);
$fileDb = new FileDb('users.csv', $jsonResponse);

(new MainService($request, $jsonResponse, $log, $fileDb))->run();