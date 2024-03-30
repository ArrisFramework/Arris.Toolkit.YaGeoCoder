<?php

namespace Arris\Toolkit\YandexGeo;

use Arris\Toolkit\YandexGeo\Exception\CurlError;
use Arris\Toolkit\YandexGeo\Exception\MapsError;
use Arris\Toolkit\YandexGeo\Exception\ServerError;

/**
 * Class Api
 * @package Yandex\Geo
 * @license The MIT License (MIT)
 * @see http://api.yandex.ru/maps/doc/geocoder/desc/concepts/About.xml
 */
class Api
{
    /**
     * Дом
     */
    const KIND_HOUSE = 'house';

    /**
     * Улица
     */
    const KIND_STREET = 'street';

    /**
     * Станция метро
     */
    const KIND_METRO = 'metro';

    /** Район города */
    const KIND_DISTRICT = 'district';

    /**
     * Район области
     */
    const KIND_AREA = 'area';

    /**
     * Населенный пункт (город/поселок/деревня/село/...)
     */
    const KIND_LOCALITY = 'locality';

    /**
     * русский (по умолчанию)
     */
    const LANG_RU = 'ru-RU';

    /**
     * украинский
     */
    const LANG_UA = 'uk-UA';

    /**
     * белорусский
     */
    const LANG_BY = 'be-BY';

    /**
     * Американский английский
     */
    const LANG_US = 'en-US';

    /**
     * Британский английский
     */
    const LANG_BR = 'en-BR';

    /**
     * Турецкий (только для карты Турции)
     */
    const LANG_TR = 'tr-TR';

    /**
     * @var string Версия используемого api
     */
    protected string $_version = '1.x';

    /**
     * @var array
     */
    protected array $_filters = [];

    /**
     * @var Response|null
     */
    protected ?Response $_response;

    /**
     * @param string|null $version
     */
    public function __construct(string $version = null)
    {
        $this->_version = $version ?? $this->_version;

        $this->clear();
    }

    /**
     * @param array $options Curl options
     * @return $this
     * @throws Exception
     * @throws Exception\CurlError
     * @throws Exception\ServerError
     */
    public function load(array $options = []): Api
    {
        $apiUrl = \sprintf(
            'https://geocode-maps.yandex.ru/%s/?%s',
            $this->_version,
            \http_build_query($this->_filters)
        );

        $curl = \curl_init($apiUrl);

        $options += array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPGET => 1,
            CURLOPT_FOLLOWLOCATION => 1,
        );

        \curl_setopt_array($curl, $options);

        $data = \curl_exec($curl);
        $code = \curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (\curl_errno($curl)) {
            $error = \curl_error($curl);
            \curl_close($curl);
            throw new CurlError($error);
        }

        \curl_close($curl);

        if (\in_array($code, [500, 502])) {
            $msg = \strip_tags($data);
            throw new ServerError(\trim($msg), $code);
        }

        $data = \json_decode($data, true);

        if (empty($data)) {
            $msg = \sprintf("Can't load data by url: %s", $apiUrl);
            throw new Exception($msg);
        }

        if (!empty($data['error'])) {
            if (\is_array($data['error'])) {
                throw new MapsError($data['error']['message'], $data['error']['code']);
            } else if (!empty($data['message'])) {
                $code = !empty($data['statusCode']) ? $data['statusCode'] : 0;
                throw new MapsError($data['message'], $code);
            } else {
                throw new MapsError($data['error']);
            }
        }

        $this->_response = new Response($data);

        return $this;
    }

    /**
     * @return Response
     */
    public function getResponse()
    {
        return $this->_response;
    }

    /**
     * Очистка фильтров гео-кодирования
     * @return self
     */
    public function clear(): Api
    {
        $this->_filters = ['format' => 'json'];

        // указываем явно значения по-умолчанию
        $this
            ->setLang(self::LANG_RU)
            ->setOffset(0)
            ->setLimit(10);

        $this->_response = null;
        return $this;
    }

    /**
     * Гео-кодирование по координатам
     * @see http://api.yandex.ru/maps/doc/geocoder/desc/concepts/input_params.xml#geocode-format
     * @param float $longitude Долгота в градусах
     * @param float $latitude Широта в градусах
     * @return self
     */
    public function setPoint(float $longitude, float $latitude): Api
    {
        $this->_filters['geocode'] = \sprintf('%F,%F', $longitude, $latitude);

        return $this;
    }

    /**
     * Географическая область поиска объекта
     *
     * @param float $lengthLng Разница между максимальной и минимальной долготой в градусах
     * @param float $lengthLat Разница между максимальной и минимальной широтой в градусах
     * @param float|null $longitude Долгота в градусах
     * @param float|null $latitude Широта в градусах
     * @return self
     */
    public function setArea(float $lengthLng, float $lengthLat, float $longitude = null, float $latitude = null): Api
    {
        $this->_filters['spn'] = \sprintf('%f,%f', $lengthLng, $lengthLat);

        if (!empty($longitude) && !empty($latitude)) {
            $this->_filters['ll'] = \sprintf('%f,%f', $longitude, $latitude);
        }

        return $this;
    }

    /**
     * Позволяет ограничить поиск объектов областью, заданной self::setArea()
     * @param boolean $areaLimit
     * @return self
     */
    public function useAreaLimit(bool $areaLimit): Api
    {
        $this->_filters['rspn'] = $areaLimit ? 1 : 0;

        return $this;
    }

    /**
     * Гео-кодирование по запросу (адрес/координаты)
     * @param string $query
     * @return self
     */
    public function setQuery(string $query): Api
    {
        $this->_filters['geocode'] = $query;

        return $this;
    }

    /**
     * Вид топонима (только для обратного геокодирования)
     * @param string $kind
     * @return self
     */
    public function setKind(string $kind): Api
    {
        $this->_filters['kind'] = $kind;

        return $this;
    }

    /**
     * Максимальное количество возвращаемых объектов (по-умолчанию 10)
     * @param int $limit
     * @return self
     */
    public function setLimit(int $limit): Api
    {
        $this->_filters['results'] = $limit;

        return $this;
    }

    /**
     * Количество объектов в ответе (начиная с первого), которое необходимо пропустить
     * @param int $offset
     * @return self
     */
    public function setOffset(int $offset): Api
    {
        $this->_filters['skip'] = $offset;

        return $this;
    }

    /**
     * Предпочитаемый язык описания объектов
     *
     * @param string $lang
     * @return self
     */
    public function setLang(string $lang): Api
    {
        $this->_filters['lang'] = $lang;

        return $this;
    }

    /**
     * Ключ API Яндекс.Карт
     *
     * Создаем новый API-ключ на https://developer.tech.yandex.ru/services/3
     * @see https://tech.yandex.ru/maps/doc/geocoder/desc/concepts/input_params-docpage
     *
     * @param string $token
     * @return self
     */
    public function setToken(string $token): Api
    {
        $this->_filters['apikey'] = $token;

        return $this;
    }
}
