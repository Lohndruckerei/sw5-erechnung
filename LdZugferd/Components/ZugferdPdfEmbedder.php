<?php

namespace LdZugferd\Components;

use Mpdf\Mpdf;

/**
 * Stellt eine mPDF-Instanz so ein, dass beim Schreiben direkt ein
 * ZUGFeRD 2.x / Factur-X PDF entsteht:
 *  - PDF/A-3b
 *  - eingebettete factur-x.xml (AFRelationship "Alternative")
 *  - XMP-Metadaten mit Factur-X-Erweiterungsschema
 *
 * Muss VOR WriteHTML() aufgerufen werden.
 */
class ZugferdPdfEmbedder
{
    const FILE_NAME = 'factur-x.xml';
    const NS_FX = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

    public function apply(Mpdf $mpdf, string $xml, string $conformanceLevel = 'EN 16931', string $documentType = 'INVOICE')
    {
        $mpdf->PDFA = true;
        // Nicht PDF/A-konforme Inhalte (z. B. Transparenzen, nicht einbettbare Fonts) automatisch korrigieren,
        // statt mit einer Exception abzubrechen.
        $mpdf->PDFAauto = true;
        $mpdf->PDFAversion = '3-B';

        $mpdf->SetAssociatedFiles([[
            'name' => self::FILE_NAME,
            'mime' => 'text/xml',
            'description' => 'Factur-X/ZUGFeRD Invoice',
            'AFRelationship' => 'Alternative',
            'content' => $xml,
        ]]);

        $mpdf->SetAdditionalXmpRdf($this->buildXmpRdf($conformanceLevel, $documentType));
    }

    private function buildXmpRdf(string $conformanceLevel, string $documentType): string
    {
        $properties = [
            'DocumentFileName' => 'The name of the embedded XML document',
            'DocumentType' => 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER',
            'Version' => 'The actual version of the standard applying to the embedded XML document',
            'ConformanceLevel' => 'The conformance level of the embedded XML document',
        ];

        $propertyXml = '';
        foreach ($properties as $name => $description) {
            $propertyXml .= '
          <rdf:li rdf:parseType="Resource">
           <pdfaProperty:name>' . $name . '</pdfaProperty:name>
           <pdfaProperty:valueType>Text</pdfaProperty:valueType>
           <pdfaProperty:category>external</pdfaProperty:category>
           <pdfaProperty:description>' . $description . '</pdfaProperty:description>
          </rdf:li>';
        }

        return '   <rdf:Description rdf:about="" xmlns:fx="' . self::NS_FX . '">
    <fx:DocumentType>' . htmlspecialchars($documentType, ENT_XML1) . '</fx:DocumentType>
    <fx:DocumentFileName>' . self::FILE_NAME . '</fx:DocumentFileName>
    <fx:Version>1.0</fx:Version>
    <fx:ConformanceLevel>' . htmlspecialchars($conformanceLevel, ENT_XML1) . '</fx:ConformanceLevel>
   </rdf:Description>
   <rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">
    <pdfaExtension:schemas>
     <rdf:Bag>
      <rdf:li rdf:parseType="Resource">
       <pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>
       <pdfaSchema:namespaceURI>' . self::NS_FX . '</pdfaSchema:namespaceURI>
       <pdfaSchema:prefix>fx</pdfaSchema:prefix>
       <pdfaSchema:property>
        <rdf:Seq>' . $propertyXml . '
        </rdf:Seq>
       </pdfaSchema:property>
      </rdf:li>
     </rdf:Bag>
    </pdfaExtension:schemas>
   </rdf:Description>
';
    }
}
