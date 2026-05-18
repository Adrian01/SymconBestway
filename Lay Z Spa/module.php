<?php

declare(strict_types=1);

class LayZSpa extends IPSModule
{
    private const SPLITTER_GUID = '{9B395CBB-BFAB-442A-10D1-C451B480ABCB}';
    private const DEVICE_TX = '{794B2893-AE1B-7E5B-6A49-EF5B64DECBD6}';
    private const DEVICE_RX = '{90A6F28E-138D-58AB-9375-69F703FA3F84}';


    public function Create()
    {
        parent::Create();

        $this->ConnectParent(self::SPLITTER_GUID);
        $this->RegisterPropertyString('DeviceID', '');
  
        $this->RegisterProfiles();
        $this->RegisterVariables();

        //Timer zur Initialisierung der Gerätedaten nach Erstellung des Device-Moduls
        $this->RegisterTimer('InitializeDevice', 0, 'BWD_InitializeDevice($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $deviceID = $this->ReadPropertyString('DeviceID');

        if ($deviceID !== '') 
            {
                $this->SetReceiveDataFilter('.*' . preg_quote($deviceID, '/') . '.*');
            } 
        else 
            {
                $this->SetReceiveDataFilter('.*');
            }

        // Initialen Request kurz nach ApplyChanges anstoßen
        $this->SetTimerInterval('InitializeDevice', 1000);
    }

    public function Destroy()
    {
        parent::Destroy();
    }


    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Öffentliche Steuerungsmethoden
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    public function InitializeDevice()
    {
        $payload = ['DataID' => self::DEVICE_TX,'Buffer' => ['Command' => 'GetLastNotification'] ];

        $this->SendDebug(__FUNCTION__, json_encode($payload), 0);
        $this->SendDataToParent(json_encode($payload));

        //Timerintervall wieder zurücksetzen
        $this->SetTimerInterval('InitializeDevice', 0);
    }

    //Gerät komplett Ein-/Ausschalten
    public function SetPower(bool $state): void
    {
        $this->RequestAction('Power', $state);
    }

    //Filter Ein-/Ausschalten
    public function SetFilter(bool $state): void
    {
        $this->RequestAction('Filter', $state);
    }

    //Heizung Ein-/Ausschalten
    public function SetHeater(bool $state): void
    {
        $this->RequestAction('Heating', $state);
    }

    //Vorgabe Solltemperatur
    public function SetTemperature(int $value): void
    {
        if ($value < 20 || $value > 40) 
            {
                throw new Exception('Die Solltemperatur muss zwischen 20 °C und 40 °C liegen');
            }

        $this->RequestAction('TargetTemperature', $value);
    }

    //AirJet Düsen Ein-/Ausschalten
    public function SetAirJet(int $value): void
    {
        if (!in_array($value, [0, 1, 2], true)) 
            {
                throw new Exception('AirJet erlaubt nur die Werte 0, 1 oder 2');
            }

        $this->RequestAction('AirJet', $value);
    }

    //HydroJet Düsen Ein-/Ausschalten
    public function SetHydroJet(bool $state): void
    {
        $this->RequestAction('HydroJet', $state);
    }


    //Übergeordnete RequestAction Methode
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                $attrs = ['power' => (bool) $Value];
                break;

            case 'Filter':
                $attrs = ['filter' => ((bool) $Value) ? 2 : 0];
                break;

            case 'Heating':
                $attrs = ['heat' => ((bool) $Value) ? 2 : 0];
                break;

            case 'TargetTemperature':
                $attrs = ['Tset' => (int) round((float) $Value)];
                break;

            case 'AirJet':
                $attrs = ['wave' => $this->MapAirJetOutgoing((int) $Value)];
                break;

            case 'HydroJet':
                $attrs = ['jet' => ((bool) $Value) ? 1 : 0];
                break;

            default:
                throw new Exception('Invalid Ident');
        }

        $this->SendDebug(__FUNCTION__, 'Request ' . $Ident . ': ' . json_encode($attrs), 0);
        $this->SendWriteAttributes($attrs);
    }

    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Eingehende Daten verarbeiten
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
    
    public function ReceiveData($JSONString): void
    {
        $this->SendDebug(__FUNCTION__ . ' RAW', (string) $JSONString, 0);

        $data = json_decode((string) $JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, 'Ungültiges JSON empfangen', 0);
            return;
        }

        $buffer = $data['Buffer'] ?? null;
        if (is_string($buffer)) {
            $buffer = json_decode($buffer, true);
        }

        if (!is_array($buffer)) {
            $this->SendDebug(__FUNCTION__, 'Kein gültiger Buffer vorhanden', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__ . ' Buffer', json_encode($buffer), 0);

        if (($buffer['cmd'] ?? '') !== 's2c_noti') {
            $this->SendDebug(__FUNCTION__, 'cmd ignoriert: ' . (string) ($buffer['cmd'] ?? 'unbekannt'), 0);
            return;
        }

        $payload = $buffer['data'] ?? null;
        if (!is_array($payload)) {
            $this->SendDebug(__FUNCTION__, 'data fehlt', 0);
            return;
        }

        $incomingDeviceID = (string) ($payload['did'] ?? '');
        $deviceID = $this->ReadPropertyString('DeviceID');

        if (($deviceID !== '') && ($incomingDeviceID !== $deviceID)) {
            $this->SendDebug(__FUNCTION__, 'Nachricht gehört zu anderem Gerät: ' . $incomingDeviceID, 0);
            return;
        }

        $attrs = $payload['attrs'] ?? null;
        if (!is_array($attrs)) {
            $this->SendDebug(__FUNCTION__, 'attrs fehlt', 0);
            return;
        }

        $this->UpdateDeviceState($attrs);
    }

    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Variablenprofile erzeugen
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------

    private function RegisterProfiles(): void
    {
        $this->RegisterSwitchProfile();
        $this->RegisterHeatingActiveProfile();
        $this->RegisterTemperatureProfile();
        $this->RegisterAirJetProfile();
    }

    private function RegisterSwitchProfile(): void
    {
        $profileName = 'Bestway.Switch';

        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_BOOLEAN);
        }

        IPS_SetVariableProfileAssociation($profileName, 0, 'Aus', '', -1);
        IPS_SetVariableProfileAssociation($profileName, 1, 'Ein', '', -1);
    }

    private function RegisterHeatingActiveProfile(): void
    {
        $profileName = 'Bestway.HeatingActive';

        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_BOOLEAN);
        }

        IPS_SetVariableProfileAssociation($profileName, 0, 'Standby', '', -1);
        IPS_SetVariableProfileAssociation($profileName, 1, 'Aktiv', '', -1);
    }

    private function RegisterTemperatureProfile(): void
    {
        $profileName = 'Bestway.Temperature';

        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_FLOAT);
        }

        IPS_SetVariableProfileText($profileName, '', ' °C');
        IPS_SetVariableProfileValues($profileName, 20, 40, 1);
        IPS_SetVariableProfileDigits($profileName, 1);
    }

    private function RegisterAirJetProfile(): void
    {
        $profileName = 'Bestway.AirJet';

        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_INTEGER);
        }

        IPS_SetVariableProfileAssociation($profileName, 0, 'Aus', '', -1);
        IPS_SetVariableProfileAssociation($profileName, 1, 'Stufe 1', '', -1);
        IPS_SetVariableProfileAssociation($profileName, 2, 'Stufe 2', '', -1);
    }


    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Variablen erzeugen
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
   
    private function RegisterVariables(): void
    {
        $this->RegisterVariableBoolean('Power', 'Power', 'Bestway.Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableBoolean('Filter', 'Filter', 'Bestway.Switch', 20);
        $this->EnableAction('Filter');

        $this->RegisterVariableBoolean('Heating', 'Heizung', 'Bestway.Switch', 30);
        $this->EnableAction('Heating');

        $this->RegisterVariableBoolean('HeatingActive', 'Heizung aktiv', 'Bestway.HeatingActive', 40);

        $this->RegisterVariableFloat('WaterTemperature', 'Wassertemperatur', 'Bestway.Temperature', 50);

        $this->RegisterVariableFloat('TargetTemperature', 'Solltemperatur', 'Bestway.Temperature', 60);
        $this->EnableAction('TargetTemperature');

        $this->RegisterVariableInteger('AirJet', 'AirJet Düsen', 'Bestway.AirJet', 70);
        $this->EnableAction('AirJet');

        $this->RegisterVariableBoolean('HydroJet', 'HydroJet Düsen', 'Bestway.Switch', 80);
        $this->EnableAction('HydroJet');

        $this->RegisterVariableString('ErrorCode', 'Fehlercode', '', 90);
    }



    // -------------------------------------------------------------------------------------------------------------------------------------------------------------
	// Hilfsfunktionen
	// -------------------------------------------------------------------------------------------------------------------------------------------------------------
    
    //Variablen aktualisieren
    private function UpdateDeviceState(array $attrs): void
    {
        if (array_key_exists('power', $attrs)) {
            $this->SetValue('Power', (bool) $attrs['power']);
        }

        if (array_key_exists('filter', $attrs)) {
            $this->SetValue('Filter', ((int) $attrs['filter']) > 0);
        }

        if (array_key_exists('heat', $attrs)) {
            $heat = (int) $attrs['heat'];

            // 0 = Aus
            // 2 = Ein
            // 3/5/6 = Heizung aktiv
            // 4 = Heizung Standby
            $this->SetValue('Heating', $heat > 0);
            $this->SetValue('HeatingActive', $heat === 3 || $heat === 5 || $heat === 6);
        }

        if (array_key_exists('Tnow', $attrs)) {
            $this->SetValue('WaterTemperature', (float) $attrs['Tnow']);
        }

        if (array_key_exists('Tset', $attrs)) {
            $this->SetValue('TargetTemperature', (float) $attrs['Tset']);
        }

        if (array_key_exists('wave', $attrs)) {
            $this->SetValue('AirJet', $this->MapAirJetIncoming((int) $attrs['wave']));
        }

        if (array_key_exists('jet', $attrs)) {
            $this->SetValue('HydroJet', ((int) $attrs['jet']) > 0);
        }

        $errorCodes = $this->GetActiveErrorCodes($attrs);
        $this->SetValue('ErrorCode', $errorCodes === [] ? 'OK' : implode(', ', $errorCodes));
    }

    //Commandos an den Bestway Splitter senden
    private function SendWriteAttributes(array $attrs): void
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        if ($deviceID === '') {
            throw new Exception('DeviceID ist nicht gesetzt');
        }

        $payload = [
            'DataID' => self::DEVICE_TX,
            'Buffer' => [
                'Command' => 'Control',
                'Payload' => [
                    'cmd'  => 'c2s_write',
                    'data' => [
                        'did'   => $deviceID,
                        'attrs' => $attrs
                    ]
                ]
            ]
        ];

        $this->SendDebug(__FUNCTION__, json_encode($payload), 0);
        $this->SendDataToParent(json_encode($payload));
    }

    //Mapping AirJet 
    private function MapAirJetOutgoing(int $value): int
    {
        switch ($value) {
            case 0:
                return 0;

            case 1:
                return 40;

            case 2:
                return 100;

            default:
                throw new Exception('Ungültiger AirJet-Wert');
        }
    }

    //Mapping AirJet 
    private function MapAirJetIncoming(int $value): int
    {
        switch ($value) {
            case 0:
                return 0;

            case 40:
                return 1;

            case 100:
                return 2;

            default:
                return 0;
        }
    }
    
    //Errorcodes verarbeiten (offizielle Lay-Z-Spa Fehlercodes gemäß Hersteller)
    private function GetActiveErrorCodes(array $attrs): array
    {
        $knownErrorCodes = ['E01', 'E02', 'E03', 'E04', 'E05', 'E08', 'E09', 'E10', 'E11', 'E12', 'E13'];

        $errors = [];

        foreach ($knownErrorCodes as $key) {
            if (!array_key_exists($key, $attrs)) {
                continue;
            }

            if ((bool) $attrs[$key]) {
                $errors[] = $key;
            }
        }

        return $errors;
    }
}