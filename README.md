# LdZugferd – ZUGFeRD-/Factur-X-E-Rechnung für Shopware 5

Shopware-5-Plugin, das Rechnungs-PDFs **direkt beim Erzeugen** als ZUGFeRD-2.x-/Factur-X-Rechnung
schreibt: **Profil EN 16931 (COMFORT), PDF/A-3b mit eingebetteter `factur-x.xml`**.
Es gibt keinen zweiten Bearbeitungsschritt am fertigen PDF.

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
* Beträge: Bei Brutto-Bestellungen bleibt der Bruttobetrag je Steuersatz exakt erhalten, die XML-Summe entspricht also dem PDF.
  Bei Netto-Bestellungen wird die Steuer wie in Shopware positionsweise gerundet. Weicht die XML-Summe
  vom Bestellbetrag ab, schreibt das Plugin eine Warnung ins Log.

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
  pip install saxonche && python3 validate-schematron.py <EN16931-CII-validation.xslt> /tmp/out/*.xml
  ```
  Die XSLT steht im Repository `ConnectingEurope/eInvoicing-EN16931` unter `cii/xslt/`.

Stand der Entwicklung: Alle Testfälle (brutto, netto/B2B, EU steuerfrei, Export, Gutschrift) bestehen
das CII-D16B-XSD und die offizielle EN-16931-Schematron ohne Fehler und Warnungen. Die Einbettung (PDF/A-3-Kennung,
`/AF`, `/EmbeddedFiles`, OutputIntent, XMP) ist geprüft, die eingebettete XML ist byte-identisch.
**Noch offen: ein Test im echten Shop mit Kuzo und eine veraPDF-Prüfung mit dem echten Rechnungs-Template.**

## Bekannte Grenzen und Hinweise

* **PDF/A und Template**: mPDF korrigiert Nicht-PDF/A-Inhalte automatisch (`PDFAauto`). Ein Logo als PNG mit
  Transparenz kann dabei sichtbar verändert werden. Empfehlung: Logo als JPG oder PNG ohne Alphakanal verwenden.
* **Kuzo**: Das Plugin funktioniert, wenn Kuzo Belege über `Shopware_Components_Document` erzeugt (Standardweg in SW5).
  Erzeugt Kuzo PDFs mit einer eigenen PDF-Bibliothek, greift der Hook nicht. Das zeigt der Test unter Punkt 5.
* **Öffentliche Auftraggeber (B2G)** verlangen meist **XRechnung** mit Leitweg-ID. Das Feld „Käuferreferenz“ kann per
  Freitextfeld befüllt werden. Ein vollständiges XRechnung-Profil ist in dieser Version nicht enthalten.
* Rabatte und Gutscheine erscheinen als Positionen mit negativer Menge, nicht als Belegrabatt (BG-20). Das ist EN-16931-konform.
* SEPA-Lastschrift (59) wird ohne Mandatsreferenz und Gläubiger-ID ausgegeben. Für EN 16931 reicht das, für XRechnung nicht.

## Rechtlicher Rahmen (Wachstumschancengesetz, B2B im Inland)

* seit 01.01.2025: Pflicht zum **Empfang** von E-Rechnungen
* ab 01.01.2027: Pflicht zum **Versand** für Unternehmen mit mehr als 800.000 € Vorjahresumsatz
* ab 01.01.2028: Pflicht zum Versand für alle Unternehmen
* Rechnungen an Privatkunden (B2C) sind nicht betroffen. ZUGFeRD ab Profil EN 16931 gilt als E-Rechnung,
  MINIMUM und BASIC WL gelten nicht als E-Rechnung.
