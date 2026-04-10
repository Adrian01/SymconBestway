<?php

declare(strict_types=1);

class BestwayCloudSplitter extends IPSModule
{

    private const IO_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'; 
    private const IO_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';

    private const DEVICE_TX = '{794B2893-AE1B-7E5B-6A49-EF5B64DECBD6}';
    private const DEVICE_RX = '{90A6F28E-138D-58AB-9375-69F703FA3F84}';

    private const CONFIGURATOR_TX = '{46D61951-AEDB-BFC8-8224-EAC2DEAF911E}';
    private const CONFIGURATOR_RX = '{85B6DB8A-D3E5-90CA-1DA1-F1BAE1961EB0}';


    private const APP_ID = '98754e684ec045528b073876c34c7348';
    private const BASE_URL = 'https://euapi.gizwits.com/app';

    public function Create()
    {
        parent::Create();

        $this->RequireParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}');

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeString('UID', '');
        $this->RegisterAttributeInteger('TokenExpire', 0);

        $this->RegisterAttributeString('Host', '');
        $this->RegisterAttributeInteger('Port', 0);
        $this->RegisterAttributeString('Devices', '');
        $this->RegisterAttributeBoolean('WebSocketConnectionState', false);

        $this->RegisterTimer('Heartbeat', 0, 'BWS_SendHeartbeat($_IPS["TARGET"]);');

    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        if (IPS_GetKernelRunlevel() !== KR_READY) 
            {
                return;
            }

        $this->RegisterMessage(IPS_GetInstance($this->InstanceID)['ConnectionID'], IM_CHANGESTATUS);
        $this->WriteAttributeBoolean('WebSocketConnectionState', false);

    }

    //Empfang vom WebSocket Instanzzustand
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, 'SenderID: ' . $SenderID . ' | Message: ' . $Message . ' | Data: ' . json_encode($Data), 0);
        $status = $Data[0] ?? -1;

        if ($status === IS_ACTIVE) 
            {
                $this->SendDebug(__FUNCTION__, 'Parent aktiv, starte WebSocket Authentifizierung...', 0);
                $this->AuthenticateWebSocket();
                return;
            }

        $this->WriteAttributeBoolean('WebSocketConnectionState', false);
        $this->SetTimerInterval('Heartbeat', 0);

    }

    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Datenfluss vom IO (WebSocket)
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $data = json_decode((string) $JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug('[WebSocket] ERROR', 'Ungültiges JSON im ReceiveData', 0);
            return;
        }

        $buffer = (string) ($data['Buffer'] ?? '');
        $this->SendDebug('[WebSocket] Receive', $buffer, 0);

        $wsData = json_decode($buffer, true);
        if (!is_array($wsData)) {
            $this->SendDebug('[WebSocket] ERROR', 'Buffer enthält kein gültiges JSON', 0);
            return;
        }

        $cmd = (string) ($wsData['cmd'] ?? '');

        if ($cmd === 'login_res') {
            $success = (bool) ($wsData['data']['success'] ?? false);
            $this->WriteAttributeBoolean('WebSocketConnectionState', $success);

            if ($success) 
            {
                $this->SendDebug('[WebSocket] Receive', 'Login erfolgreich', 0);
                $this->SetTimerInterval('Heartbeat', 120000);
            } 
            else 
            {
                $this->SendDebug('[WebSocket] ERROR', 'Login fehlgeschlagen', 0);
                $this->SetTimerInterval('Heartbeat', 0);
            }
            return;

            }

        if ($cmd === 's2c_invalid_msg') 
            {
                $errorCode = (int) ($wsData['data']['error_code'] ?? 0);
                $message = (string) ($wsData['data']['msg'] ?? '');

                $this->SendDebug('[WebSocket] ERROR', $errorCode . ': ' . $message, 0);

                if (in_array($errorCode, [1003, 1009, 1011], true)) 
                {
                    $this->WriteAttributeBoolean('WebSocketConnectionState', false);
                    $this->SetTimerInterval('Heartbeat', 0);

                if ($this->IsParentActive()) 
                    {
                        $this->AuthenticateWebSocket();
                    }
                }
                return;

            }

        if ($cmd === 's2c_noti') 
            {
                //Letzte WebSocket Nachricht zwischenspeichern
                $this->SetBuffer('LastNotification', json_encode($wsData));

                $payload = ['DataID' => self::DEVICE_RX, 'Buffer' => $this->GetBuffer('LastNotification')];
                $this->SendDebug('[Device] Send', json_encode($payload), 0);

                $this->SendDataToChildren(json_encode($payload));
                return;
            }



    }

	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Datenfluss von Konfigurator und Device zu Splitter und IO (WebSocket)
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
    public function ForwardData($JSONString)

    {
        $data = json_decode((string) $JSONString, true);
        if (!is_array($data)) {
            return json_encode([
                'Status'  => 'Error',
                'Message' => 'Ungültiges JSON'
            ]);
        }

        $dataID = (string) ($data['DataID'] ?? '');
        $buffer = $data['Buffer'] ?? null;

        if (!is_array($buffer)) {
            return json_encode([
                'Status'  => 'Error',
                'Message' => 'Ungültiger Buffer'
            ]);
        }

        // Requests from the configurator
        if ($dataID === self::CONFIGURATOR_TX) {
            $command = (string) ($buffer['Command'] ?? '');
            $this->SendDebug('[Configurator] Request', 'Command: ' . $command, 0);

            if ($command === 'GetDevices') {
                if (!$this->FetchDevices()) {
                    return json_encode([
                        'Status'  => 'Error',
                        'Message' => 'FetchDevices fehlgeschlagen!'
                    ]);
                }

                $devices = json_decode($this->ReadAttributeString('Devices'), true);

                return json_encode([
                    'Status'  => 'OK',
                    'Devices' => is_array($devices) ? $devices : []
                ]);
            }

            return json_encode([
                'Status'  => 'Error',
                'Message' => 'Unbekannter Command: ' . $command
            ]);
        }

        // Requests from the device
        if ($dataID === self::DEVICE_TX) {
            $command = (string) ($buffer['Command'] ?? '');
            $this->SendDebug('[Device] Request', 'Command: ' . $command, 0);

            if ($command === 'GetLastNotification') 
                {
        
                                $payload = ['DataID' => self::DEVICE_RX, 'Buffer' => $this->GetBuffer('LastNotification')];
                        $this->SendDebug('[Device] Send', json_encode($payload), 0);

                        $this->SendDataToChildren(json_encode($payload));
                }

            if ($command === 'Control') {
                if (!$this->ReadAttributeBoolean('WebSocketConnectionState')) {
                    return json_encode([
                        'Status'  => 'Error',
                        'Message' => 'WebSocket nicht eingeloggt'
                    ]);
                }

                $wsPayload = $buffer['Payload'] ?? null;

                $payload = [
                    'DataID' => self::IO_TX,
                    'Buffer' => json_encode($wsPayload)
                ];

                $this->SendDebug('[WebSocket] Send', json_encode($payload), 0);
                $this->SendDataToParent(json_encode($payload));

                return json_encode([
                    'Status'  => 'OK',
                    'Message' => 'Write weitergeleitet'
                ]);
            }

            return json_encode([
                'Status'  => 'Error',
                'Message' => 'Unbekannter Device-Command: ' . $command
            ]);
        }
    }


	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// WebSocket
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    //WebSocket konfiguration und Aktivierung
    public function SetupWebSocketConnection(): bool //Setup WebSocket
    {
        $host = $this->ReadAttributeString('Host');
        $port = $this->ReadAttributeInteger('Port');
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];

        if ($host === '' || $port <= 0) 
        {
            $this->SendDebug(__FUNCTION__, 'Host oder Port ungültig', 0);
            return false;
        }

        //Create WebSocket URL
        $url = 'ws://' . $host . ':' . $port . '/ws/app/v1';

        if ($parentID === 0) 
        {
            $this->SendDebug(__FUNCTION__, 'Bestway Cloud Splitter ist mit keinem Websocket verbunden!', 0);
            return false;
        }

        IPS_SetProperty($parentID, 'URL', $url);
        IPS_SetProperty($parentID, 'Active', true);

        $this->SendDebug(__FUNCTION__, 'WebSocket URL gesetzt: ' . $url, 0);

        $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        IPS_ApplyChanges($parentID);

        return true;
    }

    //WebSocketverbindung authentifizieren
    public function AuthenticateWebSocket(): void
    {   

        $UID = $this->ReadAttributeString('UID');
        $AccessToken = $this->ReadAttributeString('Token');

        if ($UID === '' || $AccessToken === '') 
        {
            $this->SendDebug(__FUNCTION__, 'UID oder Token fehlt', 0);
            return;
        }

        $msg = [
            'cmd'  => 'login_req',
            'data' => [
                'appid'              => self::APP_ID,
                'uid'                => $UID,
                'token'              => $AccessToken,
                'p0_type'            => 'attrs_v4',
                'heartbeat_interval' => 180,
                'auto_subscribe'     => true
            ]
        ];

        $payload = json_encode($msg);
        $this->SendDebug('[WebSocket] Send', $payload, 0);

        $this->SendDataToParent(json_encode(['DataID' => self::IO_TX, 'Buffer' => $payload]));
    }

    //Heartbeat an den WebSocket senden, WebSocket wird andernfalls nach 180 Sekunden geschlossen.
    public function SendHeartbeat(): void
    {
        if (!$this->ReadAttributeBoolean('WebSocketConnectionState')) 
        {
            $this->SendDebug(__FUNCTION__, 'Heartbeat übersprungen, WebSocket nicht eingeloggt', 0);
            return;
        }

        $msg = ['cmd' => 'ping'];
        $payload = json_encode($msg);

        if ($payload === false) 
        {
            $this->SendDebug(__FUNCTION__, 'Ping konnte nicht kodiert werden', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, $payload, 0);

        $this->SendDataToParent(json_encode(['DataID' => self::IO_TX, 'Buffer' => $payload]));
    }

	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Bestway Cloud API-Funktionen
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    //Cloud Login und Tokenabruf
    public function FetchAccessToken(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Starte Login', 0);

        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        if ($username === '' || $password === '') {
            $this->SendDebug(__FUNCTION__ . '::ERROR', 'Benutzername oder Passwort leer', 0);
            return false;
        }

        $response = $this->ApiRequest('/login', [
            'username' => $username,
            'password' => $password,
            'lang'     => 'de'
        ]);

        if (!isset($response['token'])) {
            $this->SendDebug(__FUNCTION__ . '::ERROR', 'Kein Token erhalten', 0);
            return false;
        }

        $this->WriteAttributeString('Token', (string) $response['token']);
        $this->WriteAttributeString('UID', (string) ($response['uid'] ?? ''));
        $this->WriteAttributeInteger('TokenExpire', (int) ($response['expire_at'] ?? 0));

        $this->SendDebug(__FUNCTION__, 'Login erfolgreich', 0);
        $this->FetchDevices(); //PRÜFEN
        $this->UpdateFormField("pop", "visible", true); //PRÜFEN
        return true;
    }

    //Prüfung ob ein gültiger Token vorliegt
    private function EnsureAccessToken(): bool
    {
        $AccessToken = $this->ReadAttributeString('Token');
        $expire = $this->ReadAttributeInteger('TokenExpire');

        if ($AccessToken === '' || $expire === 0) {
            $this->SendDebug(__FUNCTION__, 'Kein Token oder kein Ablaufdatum vorhanden', 0);
            return false;
        }

        $now = time();
        $expireDate = date('d.m.Y H:i:s', $expire);

        if ($now >= $expire) {
            $this->SendDebug(__FUNCTION__, 'Token abgelaufen (gültig bis ' . $expireDate . ')', 0);
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'Token gültig bis ' . $expireDate, 0);
        return true;
    }

    //Registrierte Geräte abrufen
    public function FetchDevices(): bool
    {
        
        $this->SendDebug(__FUNCTION__, 'Registrierte Geräte von der Cloud holen...', 0);

        if (!$this->EnsureAccessToken()) 
        {
            $this->SendDebug(__FUNCTION__, 'Token ungültig, versuche neuen Token zu holen, führe Login aus', 0);

            if (!$this->FetchAccessToken()) 
            {
                $this->SendDebug(__FUNCTION__, 'Login fehlgeschlagen', 0);
                return false;
            }
        }

        $AccessToken = $this->ReadAttributeString('Token');
        $response = $this->ApiRequest('/bindings', null, ['X-Gizwits-User-token: ' . $AccessToken]);

        if (!isset($response['devices'])) {
            $this->SendDebug(__FUNCTION__, 'Keine Geräte vorhanden', 0);
            return false;
        }

        $devices = $response['devices'] ?? [];

        if (!is_array($devices) || count($devices) === 0) {
            $this->SendDebug(__FUNCTION__, 'Keine Geräte vorhanden', 0);
            return false;
        }

        $host = (string) ($devices[0]['host'] ?? '');
        $port = (int) ($devices[0]['ws_port'] ?? 0);

        $this->WriteAttributeString('Host', $host);
        $this->WriteAttributeInteger('Port', $port);
        $this->WriteAttributeString('Devices', json_encode($devices));

        $this->SendDebug(__FUNCTION__, 'Geräte erfolgreich abgerufen!', 0);
        return true;
    }


    //Zentrale API-Funktion für die Bestway Cloud
    private function ApiRequest(string $path, ?array $postData = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Content-Type: application/json',
            'X-Gizwits-Application-Id: ' . self::APP_ID
        ], $extraHeaders);

        $url = self::BASE_URL . $path;
        $method = $postData !== null ? 'POST' : 'GET';

        //Send Request Debug
        $this->SendDebug('Cloud Request', json_encode([
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $postData
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }

        $result = curl_exec($ch);

        if ($result === false) {
            $this->SendDebug(__FUNCTION__, 'cURL Fehler: ' . curl_error($ch), 0);
            curl_close($ch);
            return [];
        }

        curl_close($ch);

        //Send Response Debug
        $this->SendDebug('Cloud Response', (string) $result, 0);

        $data = json_decode((string) $result, true);
        if (!is_array($data)) 
            {
                $this->SetStatus(200);
                return [];
            }

        if ($this->HandleHttpError($data, 'Cloud'))
            {
                return [];
            }

        $this->SetStatus(102);
        return $data;
    }
  

    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Hilfsfunktionen
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    //Http Fehler behandeln
    private function HandleHttpError(array $response, string $context = 'Cloud Request'): bool
    {
        $errorCode = (int) ($response['error_code'] ?? 0);
        if ($errorCode === 0) 
            {
                return false;
            }

        $errorMessage  = (string) ($response['error_message'] ?? 'Unknown API error');
        $detailMessage = (string) ($response['detail_message'] ?? '');
        $message = $errorCode . ': ' . $errorMessage;

        if ($detailMessage !== '') 
            {
                $message .= ' (' . $detailMessage . ')';
            }
        
        //Rohdaten Debug HttpError
        $this->SendDebug($context . ' Error', $message, 0);

        switch ($errorCode) 
        {
            case 9005: //User does not exist
                $this->SetStatus(201);
                break;

            case 9020: //Invalid credentials
                $this->SetStatus(202);
                break;

            case 9004: //Invalid AccessToken
                $this->SetStatus(203);
                break;

            case 9041: //Request was throttled
                $this->SetStatus(204);
                break;

            default:
                $this->SetStatus(200);
                break;
        }

        return true;
    }

    //Prüfen ob die Parent Instanz (WebSocket) aktiv ist
    private function IsParentActive(): bool
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0) 
            {
                return false;
            }

        return (IPS_GetInstance($parentID)['InstanceStatus'] ?? 0) === IS_ACTIVE;
    }

}

