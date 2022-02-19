<?php

namespace Herald\GreenPass\Validation\Covid19;

use Herald\GreenPass\GreenPassEntities\Country;
use Herald\GreenPass\GreenPassEntities\Holder;
use Herald\GreenPass\GreenPassEntities\VaccinationDose;

class VaccineChecker
{
    private const  CERT_RULE_START = 'START_DAY';
    private const  CERT_RULE_END = 'END_DAY';
    private const CERT_BOOSTER = 'BOOSTER';
    private const CERT_COMPLETE = 'COMPLETE';

    private $validation_date = null;
    private $scanMode = null;
    private $cert = null;
    private $holder = null;

    public function __construct(\DateTime $validation_date, string $scanMode, Holder $holder, VaccinationDose $cert)
    {
        $this->validation_date = $validation_date;
        $this->scanMode = $scanMode;
        $this->holder = $holder;
        $this->cert = $cert;
    }

    public function checkCertificate()
    {
        if ($this->cert->isNotComplete() && !MedicinalProduct::isEma($this->cert->product, $this->cert->country)) {
            return ValidationStatus::NOT_VALID;
        }

        return $this->validate($this->cert);
    }

    /**
     * Get custom vaccine validation rules for countries.
     *
     * @param VaccinationDose $cert
     *                                   Certificate type
     * @param string          $startEnd
     *                                   const CERT_RULE_START or const CERT_RULE_END
     * @param bool            $isBooster
     *                                   true for booster dose
     *
     * @return int
     *             custom rule value
     */
    private function getVaccineCustomDaysFromValidationRules(VaccinationDose $cert, string $countryCode, string $startEnd, bool $isBooster): int
    {
        $ruleType = ValidationRules::GENERIC_RULE;
        if ($isBooster) {
            $customCycle = VaccineChecker::CERT_BOOSTER;
        } else {
            $customCycle = VaccineChecker::CERT_COMPLETE;
        }

        if ($countryCode == Country::ITALY) {
            $customCountry = Country::ITALY;
        } else {
            $customCountry = Country::NOT_ITALY;
        }
        if ($startEnd == VaccineChecker::CERT_RULE_START && !$isBooster && $cert->product == MedicinalProduct::JOHNSON) {
            $addDays = ValidationRules::getValues(ValidationRules::VACCINE_START_DAY_COMPLETE, MedicinalProduct::JOHNSON);
        } else {
            $addDays = 0;
        }

        $ruleToCheck = ValidationRules::convertRuleNameToConstant("VACCINE_{$startEnd}_{$customCycle}_{$customCountry}");

        $result = ValidationRules::getValues($ruleToCheck, $ruleType);

        if ($result == ValidationStatus::NOT_FOUND) {
            $result = ValidationRules::getDefaultValidationDays($startEnd, $countryCode);
        }

        return (int) $result + (int) $addDays;
    }

    private function validate(VaccinationDose $cert)
    {
        switch ($this->scanMode) {
            case ValidationScanMode::CLASSIC_DGP:
                $esito = $this->standardStrategy($cert);
                break;
            case ValidationScanMode::SUPER_DGP:
                $esito = $this->strengthenedStrategy($cert);
                break;
            case ValidationScanMode::BOOSTER_DGP:
                $esito = $this->boosterStrategy($cert);
                break;
            case ValidationScanMode::SCHOOL_DGP:
                $esito = $this->schoolStrategy($cert);
                break;
            case ValidationScanMode::WORK_DGP:
                $esito = $this->workStrategy($cert);
                break;
            case ValidationScanMode::ENTRY_IT_DGP:
                $esito = $this->vaccineEntryItalyStrategy($cert);
                break;
            default:
                return ValidationStatus::NOT_EU_DCC;
        }

        return $esito;
    }

    private function standardStrategy(VaccinationDose $cert)
    {
        $countryCode = Country::ITALY;

        $vaccineDate = $cert->date;

        $startDaysToAdd = 0;
        if ($cert->isComplete()) {
            $startDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, $countryCode, VaccineChecker::CERT_RULE_START, $cert->isBooster());
        } elseif ($cert->isNotComplete()) {
            $startDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_START_DAY_NOT_COMPLETE, $cert->product);
        }

        $endDaysToAdd = 0;
        if ($cert->isComplete()) {
            $endDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, $countryCode, VaccineChecker::CERT_RULE_END, $cert->isBooster());
        } elseif ($cert->isNotComplete()) {
            $endDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_END_DAY_NOT_COMPLETE, $cert->product);
        }

        $startDate = $vaccineDate->modify("+$startDaysToAdd days");
        $endDate = $vaccineDate->modify("+$endDaysToAdd days");
        $endDate = $endDate->SetTime(23, 59);

        if ($this->validation_date < $startDate) {
            return ValidationStatus::NOT_VALID_YET;
        }
        if ($this->validation_date > $endDate) {
            return ValidationStatus::EXPIRED;
        }
        if (!MedicinalProduct::isEma($cert->product, $cert->country)) {
            return ValidationStatus::NOT_VALID;
        }

        return ValidationStatus::VALID;
    }

    private function strengthenedStrategy(VaccinationDose $cert)
    {
        $countryCode = $cert->country;
        $vaccineDate = $cert->date;
        $startDaysToAdd = 0;
        $endDaysToAdd = 0;
        if ($countryCode == Country::ITALY) {
            return $this->standardStrategy($cert);
        }
        if ($cert->isNotComplete()) {
            if (MedicinalProduct::isEma($cert->product, $cert->country)) {
                $startDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_START_DAY_NOT_COMPLETE, $cert->product);
                $endDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_END_DAY_NOT_COMPLETE, $cert->product);
            } else {
                return ValidationStatus::NOT_VALID;
            }
        } elseif ($cert->isComplete()) {
            $startDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::ITALY, VaccineChecker::CERT_RULE_START, $cert->isBooster());
            $endDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::ITALY, VaccineChecker::CERT_RULE_END, $cert->isBooster());
        }
        $extendedDaysToAdd = $this->retrieveExendedDaysToAdd();

        $startDate = $vaccineDate->modify("+$startDaysToAdd days");
        $endDate = $vaccineDate->modify("+$endDaysToAdd days");
        $extendedDate = $vaccineDate->modify("+$extendedDaysToAdd days");
        $endDate = $endDate->SetTime(23, 59);
        $extendedDate = $extendedDate->SetTime(23, 59);

        if ($cert->isNotComplete()) {
            if (!MedicinalProduct::isEma($cert->product, $cert->country)) {
                return ValidationStatus::NOT_VALID;
            }
            if ($this->validation_date < $startDate) {
                $esito = ValidationStatus::NOT_VALID_YET;
            } elseif ($this->validation_date > $endDate) {
                $esito = ValidationStatus::EXPIRED;
            } else {
                $esito = ValidationStatus::VALID;
            }
        } elseif ($cert->isBooster()) {
            if ($this->validation_date < $startDate) {
                $esito = ValidationStatus::NOT_VALID_YET;
            } elseif ($this->validation_date > $endDate) {
                $esito = ValidationStatus::EXPIRED;
            } elseif (MedicinalProduct::isEma($cert->product, $cert->country)) {
                $esito = ValidationStatus::VALID;
            } else {
                $esito = ValidationStatus::TEST_NEEDED;
            }
        } else {
            if (MedicinalProduct::isEma($cert->product, $cert->country)) {
                if ($this->validation_date < $startDate) {
                    $esito = ValidationStatus::NOT_VALID_YET;
                } elseif ($this->validation_date <= $endDate) {
                    $esito = ValidationStatus::VALID;
                } elseif ($this->validation_date <= $extendedDate) {
                    $esito = ValidationStatus::TEST_NEEDED;
                } else {
                    $esito = ValidationStatus::EXPIRED;
                }
            } else {
                if ($this->validation_date < $startDate) {
                    $esito = ValidationStatus::NOT_VALID_YET;
                } elseif ($this->validation_date <= $extendedDate) {
                    $esito = ValidationStatus::TEST_NEEDED;
                } else {
                    $esito = ValidationStatus::EXPIRED;
                }
            }
        }

        return $esito;
    }

    private function boosterStrategy(VaccinationDose $cert)
    {
        $startDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::ITALY, VaccineChecker::CERT_RULE_START, $cert->isBooster());
        if ($cert->isNotComplete()) {
            $startDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_START_DAY_NOT_COMPLETE, $cert->product);
        }
        $endDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::ITALY, VaccineChecker::CERT_RULE_END, $cert->isBooster());
        if ($cert->isNotComplete()) {
            $endDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_END_DAY_NOT_COMPLETE, $cert->product);
        }
        $vaccineDate = $cert->date;

        $startDate = $vaccineDate->modify("+$startDaysToAdd days");
        $endDate = $vaccineDate->modify("+$endDaysToAdd days");
        $endDate = $endDate->SetTime(23, 59);

        if ($this->validation_date < $startDate) {
            return ValidationStatus::NOT_VALID_YET;
        }
        if ($this->validation_date > $endDate) {
            return ValidationStatus::EXPIRED;
        }

        //Data valida, controllo tipologia
        $esito = ValidationStatus::NOT_VALID;
        if ($cert->isComplete()) {
            if ($cert->isBooster()) {
                if (MedicinalProduct::isEma($cert->product, $cert->country)) {
                    $esito = ValidationStatus::VALID;
                } else {
                    $esito = ValidationStatus::TEST_NEEDED;
                }
            } else {
                $esito = ValidationStatus::TEST_NEEDED;
            }
        }

        return $esito;
    }

    private function schoolStrategy(VaccinationDose $cert)
    {
        $vaccineDate = $cert->date;
        $startDaysToAdd = 0;
        $endDaysToAdd = 0;

        $startDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::ITALY, VaccineChecker::CERT_RULE_START, $cert->isBooster());
        if ($cert->isNotComplete()) {
            $startDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_START_DAY_NOT_COMPLETE, $cert->product);
        }

        $endDaysToAdd = ($cert->isBooster()) ? $this->getVaccineCustomDaysFromValidationRules($cert, Country::ITALY, VaccineChecker::CERT_RULE_END, $cert->isBooster()) : ValidationRules::getEndDaySchool(ValidationRules::VACCINE_END_DAY_SCHOOL, ValidationRules::GENERIC_RULE);
        if ($cert->isNotComplete()) {
            $endDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_END_DAY_NOT_COMPLETE, $cert->product);
        }

        $startDate = $vaccineDate->modify("+$startDaysToAdd days");
        $endDate = $vaccineDate->modify("+$endDaysToAdd days");
        $endDate = $endDate->setTime(23, 59);

        return $this->defaultValidationResults($startDate, $endDate);
    }

    private function workStrategy(VaccinationDose $cert)
    {
        $age = $this->holder->getAgeAtGivenDate($this->validation_date);
        if ($age >= ValidationRules::VACCINE_MANDATORY_AGE) {
            return $this->strengthenedStrategy($cert);
        } else {
            return $this->standardStrategy($cert);
        }
    }

    private function vaccineEntryItalyStrategy(VaccinationDose $cert)
    {
        $vaccineDate = $cert->date;

        $startDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::NOT_ITALY, VaccineChecker::CERT_RULE_START, $cert->isBooster());
        if ($cert->isNotComplete()) {
            $startDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_START_DAY_NOT_COMPLETE, $cert->product);
        }

        $endDaysToAdd = $this->getVaccineCustomDaysFromValidationRules($cert, Country::NOT_ITALY, VaccineChecker::CERT_RULE_END, $cert->isBooster());
        if ($cert->isNotComplete()) {
            $endDaysToAdd = ValidationRules::getValues(ValidationRules::VACCINE_END_DAY_NOT_COMPLETE, $cert->product);
        }

        $startDate = $vaccineDate->modify("+$startDaysToAdd days");
        $endDate = $vaccineDate->modify("+$endDaysToAdd days");

        return $this->defaultValidationResults($startDate, $endDate);
    }

    private function retrieveExendedDaysToAdd()
    {
        $addDays = ValidationRules::getValues(ValidationRules::VACCINE_END_DAY_COMPLETE_EXTENDED_EMA, ValidationRules::GENERIC_RULE);
        if ($addDays == ValidationStatus::NOT_FOUND) {
            $addDays = 0;
        }

        return $addDays;
    }

    private function defaultValidationResults($startDate, $endDate)
    {
        if ($this->validation_date < $startDate) {
            return ValidationStatus::NOT_VALID_YET;
        }
        if ($this->validation_date > $endDate) {
            return ValidationStatus::EXPIRED;
        }
        //Data valida, controllo tipologia
        if (!MedicinalProduct::isEma($this->cert->product, $this->cert->country)) {
            return ValidationStatus::NOT_VALID;
        }
        if ($this->cert->isNotComplete()) {
            return ValidationStatus::NOT_VALID;
        }

        return ValidationStatus::VALID;
    }
}