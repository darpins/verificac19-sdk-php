<?php

namespace Herald\GreenPass\GreenPassEntities;

use Herald\GreenPass\Validation\Covid19\MedicinalProduct;

class VaccinationDose extends CertificateType
{
    /**
     * Type of the vaccine or prophylaxis used.
     *
     * @var string|null
     */
    public $type;

    /**
     * Medicinal product used for this specific dose of vaccination.
     *
     * @var string|null
     */
    public $product;

    /**
     * Vaccine marketing authorization holder or manufacturer.
     *
     * @var string|null
     */
    public $manufacturer;

    /**
     * Sequence number (positive integer) of the dose given
     * during this vaccination event.
     *
     * @var int
     */
    public $doseGiven;

    /**
     * Total number of doses (positive integer) in a complete vaccination
     * series according to the used vaccination protocol.
     *
     * @var int
     */
    public $totalDoses;

    /**
     * The date when the described dose was received.
     */
    public $date;

    public function __construct($data)
    {
        $this->id = $data['v'][0]['ci'] ?? null;

        $this->diseaseAgent = DiseaseAgent::resolveById($data['v'][0]['tg']);

        $this->country = $data['v'][0]['co'] ?? null;
        $this->issuer = $data['v'][0]['is'] ?? null;

        $this->type = $data['v'][0]['vp'] ?? null;
        $this->product = $data['v'][0]['mp'] ?? null;
        $this->manufacturer = $data['v'][0]['ma'] ?? null;
        $this->doseGiven = $data['v'][0]['dn'] ?? 0;
        $this->totalDoses = $data['v'][0]['sd'] ?? 0;
        $this->date = !empty($data['v'][0]['dt'] ?? null) ? new \DateTimeImmutable($data['v'][0]['dt']) : null;
    }

    public function isComplete()
    {
        return $this->doseGiven >= $this->totalDoses;
    }

    public function isNotComplete()
    {
        return $this->doseGiven < $this->totalDoses;
    }

    public function isBooster()
    {
        if (!$this->isComplete()) {
            return false;
        }
        // j&j booster
        // https://github.com/ministero-salute/it-dgc-verificac19-sdk-android/commit/6812542889b28343acace7780e536fac9bf637a9
        $check_jj_booster = $this->product == MedicinalProduct::JOHNSON && (($this->doseGiven > $this->totalDoses) || ($this->doseGiven == $this->totalDoses && $this->doseGiven >= 2));
        $check_other_booster = $this->doseGiven > $this->totalDoses || ($this->doseGiven == $this->totalDoses && $this->doseGiven > 2);

        return $check_jj_booster || $check_other_booster;
    }
}