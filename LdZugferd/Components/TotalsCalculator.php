<?php

namespace LdZugferd\Components;

/**
 * Berechnet Positions-, Steuer- und Summenbeträge so, dass sie die Rechenregeln
 * der EN 16931 (BR-CO-10 ... BR-CO-16, BR-S-08/09) einhalten.
 *
 * Bei Brutto-Bestellungen bleibt der Bruttobetrag je Steuersatz exakt erhalten
 * (= Betrag auf dem PDF). Netto-Basis und Steuer werden pro Steuersatz
 * berechnet und Rundungsdifferenzen auf die größte Position verteilt.
 *
 * Eingabe je Position:
 *   name, sku, quantity, unitCode, gross (Zeilenbetrag brutto), net (Zeilenbetrag netto),
 *   rate (Steuersatz in %), category (S, Z, E, K, G), exemptionReason, exemptionReasonCode
 */
class TotalsCalculator
{
    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array{lines: array, taxes: array, totals: array}
     */
    public function calculate(array $lines, bool $netPrices): array
    {
        $groups = [];
        foreach ($lines as $index => $line) {
            $line['gross'] = round((float) $line['gross'], 2);
            $line['net'] = round((float) $line['net'], 2);
            $line['rate'] = round((float) $line['rate'], 2);
            $lines[$index] = $line;

            $key = $line['category'] . '|' . number_format($line['rate'], 2, '.', '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $line['category'],
                    'rate' => $line['rate'],
                    'exemptionReason' => isset($line['exemptionReason']) ? $line['exemptionReason'] : null,
                    'exemptionReasonCode' => isset($line['exemptionReasonCode']) ? $line['exemptionReasonCode'] : null,
                    'indexes' => [],
                ];
            }
            $groups[$key]['indexes'][] = $index;
        }

        $taxes = [];
        foreach ($groups as $group) {
            $rate = $group['rate'];
            $lineNetSum = 0.0;
            $grossSum = 0.0;
            foreach ($group['indexes'] as $i) {
                $lineNetSum += $lines[$i]['net'];
                $grossSum += $lines[$i]['gross'];
            }
            $lineNetSum = round($lineNetSum, 2);
            $grossSum = round($grossSum, 2);

            if ($netPrices || $rate == 0.0) {
                $basis = $lineNetSum;
                $tax = round($basis * $rate / 100, 2);
                // Shopware rundet die Steuer bei Netto-Bestellungen positionsweise (Summe brutto - netto).
                // Diesen Wert übernehmen, damit XML und PDF centgenau übereinstimmen
                // (BR-S-09 erlaubt eine Toleranz von 1,00).
                $lineTax = round($grossSum - $lineNetSum, 2);
                if ($grossSum != 0.0 && abs($lineTax - $tax) < 0.5) {
                    $tax = $lineTax;
                }
            } else {
                $basis = round($grossSum * 100 / (100 + $rate), 2);
                $tax = round($grossSum - $basis, 2);
            }

            // Rundungsdifferenz auf die betragsmäßig größte Position der Gruppe legen (BR-S-08).
            $diff = round($basis - $lineNetSum, 2);
            if ($diff != 0.0) {
                $target = $group['indexes'][0];
                foreach ($group['indexes'] as $i) {
                    if (abs($lines[$i]['net']) > abs($lines[$target]['net'])) {
                        $target = $i;
                    }
                }
                $lines[$target]['net'] = round($lines[$target]['net'] + $diff, 2);
            }

            $taxes[] = [
                'category' => $group['category'],
                'rate' => $rate,
                'basis' => $basis,
                'tax' => $tax,
                'exemptionReason' => $group['exemptionReason'],
                'exemptionReasonCode' => $group['exemptionReasonCode'],
            ];
        }

        $resultLines = [];
        $lineTotal = 0.0;
        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            if ($quantity == 0.0) {
                $quantity = 1.0;
            }
            $netAmount = $line['net'];
            // Netto-Einzelpreis darf nicht negativ sein (BR-27) -> negative Menge für Gutscheine/Rabatte.
            if ($netAmount < 0 && $quantity > 0) {
                $quantity = -$quantity;
            }
            $line['quantity'] = $quantity;
            $line['netAmount'] = $netAmount;
            $line['netPrice'] = round($netAmount / $quantity, 4);
            $lineTotal += $netAmount;
            $resultLines[] = $line;
        }
        $lineTotal = round($lineTotal, 2);

        $taxTotal = 0.0;
        $basisTotal = 0.0;
        foreach ($taxes as $t) {
            $taxTotal += $t['tax'];
            $basisTotal += $t['basis'];
        }
        $taxTotal = round($taxTotal, 2);
        $basisTotal = round($basisTotal, 2);

        return [
            'lines' => $resultLines,
            'taxes' => $taxes,
            'totals' => [
                'lineTotal' => $lineTotal,
                'taxBasis' => $basisTotal,
                'taxTotal' => $taxTotal,
                'grandTotal' => round($basisTotal + $taxTotal, 2),
            ],
        ];
    }
}
