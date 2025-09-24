<?php

class Crit extends X2Model {
    public static function model($className = __CLASS__) {
        return parent::model($className);
    }

    public function tableName() {
        return 'x2_crit'; // ou le nom réel de votre table personnalisée
    }
}
