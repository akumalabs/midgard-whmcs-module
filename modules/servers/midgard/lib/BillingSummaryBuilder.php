<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class BillingSummaryBuilder
{
    /**
     * @param array<string, mixed> $params
     * @return array{
     *   billingProduct: string,
     *   billingRecurringAmount: string,
     *   billingCycle: string,
     *   billingRegistrationDate: string,
     *   billingNextDueDate: string,
     *   billingPaymentMethod: string
     * }
     */
    public static function fromModuleParams(array $params): array
    {
        $groupName = self::readString($params, 'groupname');
        $productName = self::firstNonEmpty([
            self::readString($params, 'productname'),
            self::readString($params, 'product'),
        ]);

        $product = self::firstNonEmpty([
            self::combineGroupAndProduct($groupName, $productName),
            $productName,
            $groupName,
        ]);

        return [
            'billingProduct' => self::defaultDash($product),
            'billingRecurringAmount' => self::defaultDash(self::firstNonEmpty([
                self::readString($params, 'recurringamount'),
                self::readString($params, 'amount'),
                self::readString($params, 'firstpaymentamount'),
            ])),
            'billingCycle' => self::defaultDash(self::firstNonEmpty([
                self::readString($params, 'billingcycle'),
                self::readString($params, 'billing_cycle'),
            ])),
            'billingRegistrationDate' => self::defaultDash(self::firstNonEmpty([
                self::readString($params, 'regdate'),
                self::readString($params, 'registrationdate'),
                self::readString($params, 'registration_date'),
            ])),
            'billingNextDueDate' => self::defaultDash(self::firstNonEmpty([
                self::readString($params, 'nextduedate'),
                self::readString($params, 'next_due_date'),
            ])),
            'billingPaymentMethod' => self::defaultDash(self::firstNonEmpty([
                self::readString($params, 'paymentmethodname'),
                self::readString($params, 'paymentmethod'),
            ])),
        ];
    }

    /**
     * @param array<string> $values
     */
    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function readString(array $params, string $key): string
    {
        return trim((string) ($params[$key] ?? ''));
    }

    private static function combineGroupAndProduct(string $groupName, string $productName): string
    {
        $groupName = trim($groupName);
        $productName = trim($productName);

        if ($groupName === '' || $productName === '') {
            return '';
        }

        return $groupName . ' - ' . $productName;
    }

    private static function defaultDash(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : '-';
    }
}

