# IP-Symcon Bestway 

[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.0-blue.svg)]()
[![Version](https://img.shields.io/badge/Symcon%20Version-7.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-3-%28Stable%29-Changelog)

![LOGO](docs/img/Bestway_Logo.jpg?raw=true "logo")

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetungen)
3. [Unterstützte Gerätetypen](#3-unterstützte-gerätevarianten)
4. [Installation](#4-installation)
5. [Funktionsreferenz](#5-funktionsreferenz)
6. [Statusvariablen](#6-statusvariablen)
7. [Anhang](#7-anhang)
   1. [GUIDs der Module](#guids-der-module)
   2. [Spenden](#spenden)



## 1. Funktionsumfang

Das **Bestway Modul für IP-Symcon** ermöglicht die Integration von **Bestway Lay-Z-Spa Geräten** über die **Bestway Cloud** in IP-Symcon.  
Damit lassen sich sämtliche Gerätefunktionen, wie Düsen, Temperaturwerte, Filter, etc. steuern und überwachen.

### Aktuelle Features

- Verbindung zur Bestway Cloud über Benutzerkonto und Passwort
- Automatische Synchronisation aller verfügbaren Geräte  
- Anlage einzelner Bestway Device-Instanzen  
- Anzeige und Steuerung folgender Funktionen:
  - Ein-/Ausschalten des Whirlpools
  - Steuerung der Filterpumpe
  - Steuerung der Heizung
  - Setzen der Zieltemperatur
  - Steuerung der AirJet-Düsen
  - Steuerung der HydroJet-Düsen
    
- Anzeige und Auswertung folgender Werte:
  - Aktuelle Wassertemperatur
  - Zieltemperatur
  - Betriebszustand
- Automatische, Echtzeit-Synchronisierung aller Daten, dank WebSocket Anbindung 


## 2. Voraussetzungen

- **IP-Symcon Version:** 7.0 oder höher  
- **Bestway Account** mit registrierten Geräten (Lay-Z-Spa Whrilpools)
- **Internetverbindung** für die Kommunikation mit der Bestway Cloud API  


## 3. Unterstützte Gerätevarianten

| Typ              | Unterstützt        |
| ---------------- | ------------------ |
| HydroJet         | :white_check_mark: |
| HydroJet Pro     | :white_check_mark: |


## 4. Installation

### 4.1 Einbindung in IP-Symcon

Damit das Modul **Bestway** verwendet werden kann, muss dieses über den **Module Store** installiert werden.  
Hierfür muss der **Module Store** geöffnet werden. Dieser befindet sich im oberen rechten Bereich.  
Über das Suchfeld kann via "Bestway" das Modul **Bestway** gefunden werden.  
Beim Öffnen des gefundenen Moduls kann im folgenden Dialog die Installation des Moduls via "Installieren"-Knopf angestoßen werden.  

![Store](docs/img/search_module_store.png?raw=true "search module")

Modul installieren...

![Store](docs/img/install_module_store.png?raw=true "install module")


Im Anschluss öffnet sich der Hinzufügendialog zum Erstellen einer **Bestway Konfigurator** Instanz.

Soll die Instanz manuell hinzugefügt werden, muss eine Instanz vom **Bestway Konfigurator** erstellt werden. Dazu muss zuerst der Objektbaum geöffnet werden. In diesem den Hinzufügen-Button "+" unten rechts betätigen und Instanz auswählen.

![Store](docs/img/add_instance.png?raw=true "add_instance")

Mithilfe der Schnellsuche kann der **Bestway Konfigurator** gefunden werden.  
Der Ort sollte nicht verändert werden, der Name kann beliebig gewählt werden. Abschließend mit "OK" bestätigen.

![Store](docs/img/search_instance.png?raw=true "search_instance")

Nach der Erstellung der Konfigurator-Instanz öffnet sich diese automatisch und kann eingerichtet werden.  
Bevor der Konfigurator verwendet werden kann, muss im nachfolgenden Dialog **Schnittstelle konfigurieren** der Login in der automatisch erzeugten Instanz **Bestway Cloud-IO** durchgeführt werden.

Überspringe diesen Punkt am besten, und trage deine Zugangsdaten später direkt im Splitter ein, nach der Erstellung des Splitters, wird eine weitere Instanz (IO-Insatz) WS-Client erstellt. 

![Store](docs/img/configure_instance.png?raw=true "configure_instance")

Öffne nun die Splitter Instanz **Bestway Cloud Splitter** und trage deine Zugangsdaten ein, klicke anschließend auf "Authentifizieren", nun öffnet sich nach erfolgreichem Login an der Bestway Cloud ein Pop-Up Fenster, dies ermöglicht die Autokonfiguration des WebSocket Clients

![Store](docs/img/configure_websocket_popup.png?raw=true "configure_websocket_popup")

Alternativ kann der Websocket auch direkt über den Button "WebSocket konfigurieren" in der Splitter Instanz erfolgen. 

![Store](docs/img/configure_websocket.png?raw=true "configure_websocket")

Jetzt öffnet sich der Konfigurator und es können alle erkannten Geräte mit einem Klick auf "Erstellen" angelegt werden.

![Store](docs/img/configurator.png?raw=true "configurator")


## 5. Funktionsreferenz

Das Modul stellt folgende PHP-Funktionen zur Verfügung:

 _**Whirlpool Ein-/Ausschalten**_
```php
BW_SetPower(int $InstanceID, bool $state)
```


 _**Filterfunktion Ein-/Ausschalten**_
```php
BW_SetFilter(int $InstanceID, bool $state)
```


 _**Heizung Ein-/Ausschalten**_
```php
BW_SetHeater(int $InstanceID, bool $state)
```


 _**Stellt die Solltemperatur auf den gewünschten Wert ein**_
```php
BW_SetTemperature(int $InstanceID, int $value)
```
Es werden Werte zwischen 20 °C und 40 °C akzeptiert


 _**Schalten der AirJet Düsen auf Stufe 0, 1 oder 2**_
```php
BW_SetAirJet(int $InstanceID, int $value)
```
|    Wert     |    Beschreibung     |
|:-----------:|:-------------------:|
| 0           | Aus                 |
| 1           | Stufe 1             |
| 2           | Stufe 2             |

 _**HydroJet Düsen Ein-/Ausschalten**_
```php
BW_SetHydroJet(int $InstanceID, bool $state)
```

## 6. Statusvariablen

|         Variable           |   Typ   |                                  Beschreibung                                         |
|:--------------------------:|:-------:|:-------------------------------------------------------------------------------------:|
|      Wassertemperatur      | Integer | enthält die aktuelle Wassertemperatur im Pool                                         |
|      Heizung aktiv         | Boolean | gibt an ob die Heizung gerade auch wirklich heizt (Solltemperatur erreicht = inaktiv) |
|      Fehlercode            | String  | sollte ein Fehler anstehen, wird dieser hier ausgegeben                               |

## 7. Anhang

###  GUIDs der Module

|         Modul            |      Typ     |                   GUID                   |
| :----------------------: | :----------: | :--------------------------------------: |
|   **Bestway Splitter**   |      IO      | `{9B395CBB-BFAB-442A-10D1-C451B480ABCB}` |
| **Bestway Konfigurator** | Configurator | `{7FE00CD7-793B-952D-3AF2-AA764667556B}` |
|    **Lay-Z-Spa Device**  |    Device    | `{2FC949AD-D1B6-88EA-C058-5C13C425EDAE}` |


###  Spenden

Dieses Modul ist für die nicht kommzerielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:    

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=H35258DZU36AW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>