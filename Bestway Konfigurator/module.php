<?php

declare(strict_types=1);

class BestwayKonfigurator extends IPSModule
{

    private const DEVICE_GUID = '{2FC949AD-D1B6-88EA-C058-5C13C425EDAE}';
    private const CONFIGURATOR_TX = '{46D61951-AEDB-BFC8-8224-EAC2DEAF911E}';
    private const CONFIGURATOR_RX = '{85B6DB8A-D3E5-90CA-1DA1-F1BAE1961EB0}';


    public function Create()
    {
        parent::Create();
        $this->RequireParent('{9B395CBB-BFAB-442A-10D1-C451B480ABCB}');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    //Anfragen an Parent Senden
    public function Send(array $payload): string
    {
        $data = ['DataID' => self::CONFIGURATOR_TX, 'Buffer' => $payload];
        $this->SendDebug('[Konfigurator] Request', json_encode($data), 0);

        $result = $this->SendDataToParent(json_encode($data));
        $this->SendDebug('[Splitter] Response', (string) $result, 0);

        return (string) $result;
    }

    //Konfigurationsformular erstellen
    public function GetConfigurationForm()
    {
        $devices = $this->GetDevices();
        $values = [];

        foreach ($devices as $device) 
            {
                $deviceID = (string) ($device['did'] ?? '');
                $name = (string) ($device['dev_alias'] ?? $deviceID);

                if ($deviceID === '') 
                    {
                        continue;
                    }

                $instanceID = $this->GetInstanceIDByDeviceID($deviceID);
                $lastTimestamp = (int) ($device['state_last_timestamp'] ?? 0);

                $values[] =
                [
                    'name'             => $name,
                    'deviceID'         => $deviceID,
                    'product_name'     => (string) ($device['product_name'] ?? ''),
                    'lastUpdated'      => $this->FormatTimestamp($lastTimestamp),
                    'isOnline'         => ((bool) ($device['is_online'] ?? false)) ? 'Online' : 'Offline',
                    'lastUpdatedRaw'   => $lastTimestamp,
                    'instanceID'       => $instanceID,
                    'create' => 
                        [
                            'moduleID' => self::DEVICE_GUID,
                            'name'     => $name,
                            'configuration' => ['DeviceID' => $deviceID]       
                        ]
                ];
            }

        $form = [
            'actions' => [
                [
                    'type'    => 'Configurator',
                    'name'    => 'Devices',
                    'caption' => 'Geräte',
                    'delete'  => true,
                    'columns' => [
                        [
                            'name'    => 'name',
                            'caption' => 'Gerätename',
                            'width'   => '300px'
                        ],
                        [
                            'name'    => 'deviceID',
                            'caption' => 'Geräte-ID',
                            'width'   => '250px'
                        ],
                        [
                            'name'    => 'product_name',
                            'caption' => 'Produkt',
                            'width'   => '250px'
                        ],
                        [
                            'name'    => 'isOnline',
                            'caption' => 'Status',
                            'width'   => '250px'
                        ],
                        [
                            'name'    => 'lastUpdated',
                            'caption' => 'zuletzt aktualisiert',
                            'width'   => 'auto'
                        ]
                    ],
                    'values' => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    //Abfrage der Registrierten Geräte über den Splitter
    public function GetDevices(): array
    {
        $result = $this->Send(['Command' => 'GetDevices']);
        $data = json_decode($result, true);
        
        if (!is_array($data)) {
            return [];
        }

        if (($data['Status'] ?? '') !== 'OK') {
            $this->SendDebug(__FUNCTION__ . ' Error', $data['Message'] ?? 'Unbekannter Fehler', 0);
            return [];
        }

        return is_array($data['Devices'] ?? null) ? $data['Devices'] : [];
    }

    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Hilfsfunktionen
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    //InstanzID über DeviceID auslesen
    private function GetInstanceIDByDeviceID(string $deviceID): int
    {
        $instanceIDs = IPS_GetInstanceListByModuleID(self::DEVICE_GUID);

        foreach ($instanceIDs as $instanceID) {
            $configuration = json_decode(IPS_GetConfiguration($instanceID), true);

            if (!is_array($configuration)) {
                continue;
            }

            if (($configuration['DeviceID'] ?? '') === $deviceID) {
                return $instanceID;
            }
        }

        return 0;
    }

    //Zeitstempel formatieren
    private function FormatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        $dateTime = new DateTimeImmutable('@' . $timestamp);
        $dateTime = $dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $dateTime->format('d.m.Y H:i:s');
    }
}