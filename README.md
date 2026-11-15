# LdZugferd – ZUGFeRD-/Factur-X-E-Rechnung für Shopware 5

Shopware-5-Plugin, das Rechnungs-PDFs **direkt beim Erzeugen** als ZUGFeRD-2.x-/Factur-X-Rechnung
schreibt: **Profil EN 16931 (COMFORT), PDF/A-3b mit eingebetteter `factur-x.xml`**.
Es gibt keinen zweiten Bearbeitungsschritt am fertigen PDF.

Für **öffentliche Auftraggeber** erzeugt dasselbe Plugin automatisch eine **XRechnung 3.0** (CII-Syntax),
sobald beim Kunden eine **Leitweg-ID** hinterlegt ist (siehe [XRechnung](#xrechnung-für-behörden-leitweg-id)).

## Funktionsweise

```
Statuswechsel ──► Kuzo-Plugin ──► Shopware_Components_Document::initDocument(...)->render('pdf')
                                                                   │
                                     LdZugferd ersetzt render()  ◄─┘
                                       1. Belegnummer vergeben, Template rendern (wie Shopware)
                                       2. Bestelldaten → CII-XML (EN 16931)
                                       3. mPDF: PDF/A-3 + XML anhängen + XMP-Metadaten
                                       4. WriteHTML() → Output → files/documents/<hash>.pdf
```

* Der Hook `Shopware_Components_Document::render::replace` sitzt in der Shopware-Kernkomponente.
  Er greift deshalb bei **jeder** Belegerstellung: im Backend, in der Stapelverarbeitung und bei
  Plugins wie der automatischen Belegerstellung von Kuzo, sofern diese die Shopware-Dokumentenkomponente nutzen.
* Der Ablauf entspricht 1:1 dem Original von Shopware 5.6/5.7, auch die Events `…_FilterHtml` und `…_FilterMpdfConfig`.
  Neu ist nur, dass die mPDF-Instanz vor `WriteHTML()` auf PDF/A-3 gestellt wird und die XML eingebettet bekommt.
* Nur konfigurierte Belegtypen sind betroffen (Standard: ID 1 = Rechnung). Alle anderen Belege
  (Lieferschein usw.) und die Vorschau laufen unverändert über Shopware.
* Fehlt eine Pflichtangabe, entsteht standardmäßig das normale PDF und der Fehler landet im Plugin-Log
  (`var/log/plugin_*.log`). Die Automatik bricht dadurch nicht ab.
* Beträge: Das Plugin rundet wie Shopware positionsweise. Netto, MwSt und Gesamtbetrag der XML stimmen so centgenau
  mit dem Rechnungs-PDF überein. Weicht die XML-Summe vom Bestellbetrag ab, schreibt das Plugin eine Warnung ins Log.

## Inhalt der XML

| Bereich | Quelle |
|---|---|
| Rechnungsnr., Datum, Leistungsdatum | Shopware-Beleg (Belegnummer, Rechnungsdatum, Lieferdatum aus Beleg-Konfiguration, sonst Rechnungsdatum) |
| Verkäufer (Name, Anschrift, USt-IdNr./StNr., Kontakt, Register) | Plugin-Konfiguration |
| Käufer (Firma/Name, Anschrift, USt-IdNr., E-Mail, Kundennr.) | Rechnungsadresse der Bestellung |
| Lieferanschrift | Lieferadresse der Bestellung |
| Positionen inkl. Versand, Gutscheine/Rabatte (als Negativmenge) | Bestellpositionen |
| Steuer | S (Standard), K (innergem. Lieferung), G (Export), Z/E |
| Zahlung | Zahlungsart → UNTDID-4461-Code (konfigurierbar), IBAN/BIC, Zahlungsziel, „bereits bezahlt“ bei Zahlstatus 12 |
| Gutschrift/Storno (optional) | Typ 381 mit Verweis auf die Ursprungsrechnung |
| Käuferreferenz (BT-10) | Leitweg-ID, sonst Bestellnummer |

## XRechnung für Behörden (Leitweg-ID)

**Einrichtung**

1. In der Plugin-Konfiguration „XRechnung erzeugen“ auf **Automatisch bei Leitweg-ID** lassen (Standard).
   **Ansprechpartner, Telefon, E-Mail und IBAN** des Verkäufers ausfüllen. Für XRechnung sind sie Pflicht.
2. Beim Kunden (Backend → Kunden → Freitextfeld **„Leitweg-ID (XRechnung)“**) die Leitweg-ID eintragen,
   z. B. `991-12345678-06`. Die Behörde teilt sie mit, meist steht sie in der Bestellung oder im Auftrag.
   * Hat eine Behörde mehrere Dienststellen mit eigenen Leitweg-IDs, das gleichnamige Feld an der **Adresse** verwenden.
     Es wird bei der Bestellung in die Rechnungsadresse übernommen und hat Vorrang.
   * Steht die Leitweg-ID schon in einem anderen Freitextfeld, dieses unter „Zusätzliches Freitextfeld mit Leitweg-ID“ eintragen.

**Was dann passiert**

* Die Rechnung wird als XRechnung 3.0 erzeugt (`urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0`).
  Die Leitweg-ID steht als Käuferreferenz (BT-10) und als elektronische Adresse des Käufers (BT-49, Schema 0204) in der XML.
* Das **PDF** enthält die XRechnung eingebettet (ZUGFeRD-Profil `XRECHNUNG`). Zusätzlich wird die **reine XML**
  unter `files/documents/<hash>.xml` gespeichert, weil Behördenportale (ZRE, OZG-RE, Peppol) in der Regel nur die XML annehmen.
* Download im Backend, bei angemeldetem Benutzer:
  `https://<shop>/backend/LdZugferdXml/download?order=<Bestellnummer>`
* Leitweg-IDs mit falschem Aufbau oder falscher Prüfziffer werden im Plugin-Log gemeldet.
* Fehlt eine XRechnung-Pflichtangabe, entsteht stattdessen ZUGFeRD EN 16931 und ein Fehler im Log mit Regelnummer (z. B. `BR-DE-6`).
* Zahlungsarten Lastschrift (59) und Karte (48/54/55) verlangen in der XRechnung Mandats- bzw. Kartendaten.
  Diese kennt das Plugin nicht, deshalb wird für solche Bestellungen ZUGFeRD erzeugt. Behörden zahlen praktisch immer per Überweisung (58).

**Versand an die Behörde:** Upload der XML im Rechnungsportal (Bund: ZRE, Länder/Kommunen: z. B. OZG-RE) oder per Peppol.
Manche Behörden nehmen auch E-Mail an. Ein automatischer Versand der XML (z. B. als Mail-Anhang der Kuzo-Automatik) ist
noch nicht enthalten.

## Installation

1. Ordner `LdZugferd/` nach `custom/plugins/LdZugferd/` kopieren (oder als ZIP: `zip -r LdZugferd.zip LdZugferd`).
2. Backend → Plugin-Manager → „ZUGFeRD / Factur-X E-Rechnung“ installieren und aktivieren.
3. Konfiguration ausfüllen. Pflicht sind Firmenname, Anschrift sowie USt-IdNr. oder Steuernummer, empfohlen sind IBAN und Rechnungs-E-Mail.
   Unter „Belegtyp-IDs“ prüfen, welche ID die Rechnung in `s_core_documents` hat.
4. Alle Caches leeren, inklusive Proxies: `bin/console sw:cache:clear`.
5. Test: Bei einer Testbestellung den Status so ändern, dass Kuzo die Rechnung erzeugt, dann das PDF prüfen (siehe unten).

Voraussetzung: Shopware 5.6 oder 5.7 (mitgeliefertes mPDF 7/8), PHP-Erweiterung `zlib`.

## Prüfen

* **PDF öffnen**: In Acrobat bzw. im Firefox-PDF-Viewer muss in der Seitenleiste „Anlagen“ die Datei `factur-x.xml` erscheinen.
* **Validierung**: z. B. Mustang (`java -jar Mustang-CLI.jar --action validate --source rechnung.pdf`),
  das prüft PDF/A per veraPDF und die XML per EN-16931-Schematron. Alternativ den KoSIT-Validator verwenden.
  Bitte keine echten Kundenrechnungen in Online-Validatoren hochladen.
* **Ohne Shopware** (Entwicklung):
  ```bash
  cd tools && composer install
  php generate-sample.php gross /tmp/sample.pdf     # Beispiel-PDF + XML
  php test-invoice-data.php /tmp/out                # simulierte Shopware-Bestellungen
  php generate-sample.php xrechnung /tmp/xr.pdf     # Beispiel-XRechnung
  pip install saxonche && python3 validate-schematron.py <EN16931-CII-validation.xslt> /tmp/out/*.xml
  # XRechnung-Regeln der KoSIT (Repository itplr-kosit/xrechnung-schematron) vorher kompilieren:
  python3 compile-schematron.py XRechnung-CII-validation.sch xr-cii.xsl <schematron/trunk/schematron/code>
  python3 validate-schematron.py xr-cii.xsl /tmp/out/behoerde.xml
  ```
  Die EN-16931-XSLT steht im Repository `ConnectingEurope/eInvoicing-EN16931` unter `cii/xslt/`.

Stand der Entwicklung: Alle Testfälle (brutto, netto/B2B, EU steuerfrei, Export, Gutschrift, Behörde) bestehen
das CII-D16B-XSD und die offizielle EN-16931-Schematron ohne Fehler und Warnungen. Der Behörden-Fall besteht zusätzlich die
XRechnung-3.0-Schematron der KoSIT (v2.6.0). Die Einbettung (PDF/A-3-Kennung,
`/AF`, `/EmbeddedFiles`, OutputIntent, XMP) ist geprüft, die eingebettete XML ist byte-identisch.
**In echtem Shopware 5.7.19 getestet** (Testsystem unter [`testsystem/`](testsystem/README.md)): Eine Rechnung,
erzeugt über `Shopware_Components_Document::initDocument()->render()` wie bei Kuzo, enthält die `factur-x.xml`. Netto,
MwSt und Gesamtbetrag stimmen mit dem PDF überein, und die XML ist gültig. Mit Leitweg-ID entsteht eine gültige XRechnung,
die zusätzlich gespeichert wird und im Backend herunterladbar ist.
**Noch offen: ein Test mit Kuzo und dem eigenen Shop-Dump sowie eine veraPDF-Prüfung mit dem echten Rechnungs-Template.**

## Testsystem

Unter [`testsystem/`](testsystem/README.md) liegt eine Docker-Umgebung (`sw5-entwicklung`), in die du Dateien und
Datenbank-Dump des Live-Shops einspielst. Der Shop hat dort keinen Internetzugang, Mails landen in Mailpit, und das
Plugin wird automatisch installiert.

## Bekannte Grenzen und Hinweise

* **PDF/A und Template**: mPDF korrigiert Nicht-PDF/A-Inhalte automatisch (`PDFAauto`). Ein Logo als PNG mit
  Transparenz kann dabei sichtbar verändert werden. Empfehlung: Logo als JPG oder PNG ohne Alphakanal verwenden.
* **Kuzo**: Das Plugin funktioniert, wenn Kuzo Belege über `Shopware_Components_Document` erzeugt (Standardweg in SW5).
  Erzeugt Kuzo PDFs mit einer eigenen PDF-Bibliothek, greift der Hook nicht. Das zeigt der Test unter Punkt 5.
* Rabatte und Gutscheine erscheinen als Positionen mit negativer Menge, nicht als Belegrabatt (BG-20). Das ist EN-16931-konform.
* SEPA-Lastschrift (59) wird ohne Mandatsreferenz und Gläubiger-ID ausgegeben. Für EN 16931 reicht das, für XRechnung nicht (siehe oben).
* Die Leitweg-ID kann nur im Backend eingetragen werden. Ein Eingabefeld im Checkout oder in der Adressverwaltung des Shops
  bräuchte eine Template-Erweiterung.

## Rechtlicher Rahmen (Wachstumschancengesetz, B2B im Inland)

* seit 01.01.2025: Pflicht zum **Empfang** von E-Rechnungen
* ab 01.01.2027: Pflicht zum **Versand** für Unternehmen mit mehr als 800.000 € Vorjahresumsatz
* ab 01.01.2028: Pflicht zum Versand für alle Unternehmen
* Rechnungen an Privatkunden (B2C) sind nicht betroffen. ZUGFeRD ab Profil EN 16931 gilt als E-Rechnung,
  MINIMUM und BASIC WL gelten nicht als E-Rechnung.
