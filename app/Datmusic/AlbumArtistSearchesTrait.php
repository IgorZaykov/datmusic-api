<?php
/**
 * Copyright (c) 2020  Alashov Berkeli
 * It is licensed under GNU GPL v. 2 or later. For full terms see the file LICENSE.
 */

namespace App\Datmusic;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait AlbumArtistSearchesTrait
{
    private $getArtistTypes = ['audiosByArtist', 'albumsByArtist'];
    private $audiosByArtist = 'audiosByArtist';
    private $albumsByArtist = 'albumsByArtist';

    private $artistsSearchPrefix = 'artist:';
    private $albumSearchPrefix = 'album:';
    private $albumsSearchPrefix = 'albums:';
    private $albumsSearchLimit = 10;

    /**
     * @param Request $request
     * @param string  $query
     *
     * @return JsonResponse|bool
     */
    public function audiosByArtistName(Request $request, string $query)
    {
        $query = Str::replaceFirst($this->artistsSearchPrefix, '', $query);
        $artists = $this->searchArtists($request->merge(['q' => $query]))->getOriginalContent()['data'][self::$SEARCH_BACKEND_ARTISTS];

        logger()->searchBy('AudiosByArtistName', $query, 'Account#'.$this->accessTokenIndex, 'count='.count($artists));

        if (! empty($artists)) {
            return $this->getArtistAudios($request, $artists[0]->id);
        } else {
            return false;
        }
    }

    /**
     * @param Request $request
     * @param string  $query
     *
     * @return JsonResponse|bool
     */
    public function audiosByAlbumName(Request $request, string $query)
    {
        $query = Str::replaceFirst($this->albumSearchPrefix, '', $query);
        $albums = $this->searchAlbums($request->merge(['q' => $query]))->getOriginalContent()['data'][self::$SEARCH_BACKEND_ALBUMS];

        logger()->searchBy('AudiosByAlbumName', $query, 'Account#'.$this->accessTokenIndex, 'count='.count($albums));

        if (! empty($albums)) {
            $album = collect($albums)->sortByDesc('plays')->first();

            return $this->getAlbumById($request->merge([
                'owner_id'   => $album->owner_id,
                'access_key' => $album->access_key,
            ]), $album->id);
        } else {
            return false;
        }
    }

    /**
     * @param Request $request
     * @param string  $query
     * @param int     $limit
     *
     * @return JsonResponse|bool
     */
    public function audiosByAlbumNameMultiple(Request $request, string $query, int $limit = 10)
    {
        $query = Str::replaceFirst($this->albumsSearchPrefix, '', $query);
        $albums = $this->searchAlbums($request->merge(['q' => $query]))->getOriginalContent()['data'][self::$SEARCH_BACKEND_ALBUMS];

        logger()->searchBy('AudiosByAlbumNameMultiple', $query, 'Account#'.$this->accessTokenIndex, 'count='.count($albums));

        if (! empty($albums)) {
            $albums = collect($albums)->sortByDesc('plays')->take(min($limit, $this->albumsSearchLimit));

            return okResponse($albums->flatMap(function ($album) use ($request) {
                return $this->getAlbumById($request->merge([
                    'owner_id'   => $album->owner_id,
                    'access_key' => $album->access_key,
                ]), $album->id)->getOriginalContent()['data']['audios'];
            })->toArray(), 'audios');
        } else {
            return false;
        }
    }

    public function searchAlbums(Request $request)
    {
        return $this->searchItems($request, self::$SEARCH_BACKEND_ALBUMS);
    }

    public function searchArtists(Request $request)
    {
        return $this->searchItems($request, self::$SEARCH_BACKEND_ARTISTS);
    }

    public function getArtistAudios(Request $request, $artistId)
    {
        return $this->getArtistItems($request, $artistId, $this->audiosByArtist);
    }

    public function getArtistAlbums(Request $request, $artistId)
    {
        return $this->getArtistItems($request, $artistId, $this->albumsByArtist);
    }

    public function getAlbumById(Request $request, string $albumId)
    {
        $cacheKey = $this->getCacheKeyForId($request, $albumId);
        $cachedResult = $this->getCache($cacheKey);

        if (! is_null($cachedResult)) {
            logger()->getAlbumByIdCache($albumId);

            return $this->audiosResponse($request, $cachedResult, false);
        }

        $captchaParams = $this->getCaptchaParams($request);
        $params = [
            'access_token' => config('app.auth.tokens')[$this->accessTokenIndex],
            'album_id'     => $albumId,
            'count'        => $this->count,
            'owner_id'     => $request->get('owner_id'),
            'access_key'   => $request->get('access_key'),
        ];

        $response = as_json(vkClient()->get('method/audio.get', [
            'query' => $params + $captchaParams,
        ]
        ));

        $error = $this->checkSearchResponseError($request, $response);
        if ($error) {
            return $error;
        }

        $data = $this->parseAudioItems($response);
        $this->cacheResult($cacheKey, $data);
        logger()->getAlbumById($albumId);

        return $this->audiosResponse($request, $data);
    }

    /**
     * Search and cache mechanism for albums and artists.
     *
     * @param Request $request
     * @param string  $type
     *
     * @return JsonResponse|array
     */
    private function searchItems(Request $request, string $type)
    {
        if (! in_array($type, [self::$SEARCH_BACKEND_ALBUMS, self::$SEARCH_BACKEND_ARTISTS])) {
            abort(404);
        }

        $cacheKey = sprintf('%s.%s', $type, $this->getCacheKey($request));
        $cachedResult = $this->getCache($cacheKey, $type);

        $query = getQuery($request);
        $offset = getPage($request) * $this->count;

        if (! is_null($cachedResult)) {
            logger()->searchByCache($type, $query, $offset);

            return okResponse($cachedResult, $type);
        }

        $captchaParams = $this->getCaptchaParams($request);
        $params = [
            'access_token' => config('app.auth.tokens')[$this->accessTokenIndex],
            'q'            => $query,
            'offset'       => $offset,
            'count'        => $this->count,
        ];

        $response = as_json(vkClient()->get('method/audio.search'.ucfirst($type), [
            'query' => $params + $captchaParams,
        ]
        ));

        $error = $this->checkSearchResponseError($request, $response);
        if ($error) {
            return $error;
        }

        $data = $response->response->items;
        $this->cacheResult($cacheKey, $data, $type);
        logger()->searchBy($type, $query, $offset);

        return okResponse($data, $type);
    }

    /**
     * Search and cache mechanism for albums and artists.
     *
     * @param Request $request
     * @param string  $artistId
     * @param string  $type
     *
     * @return JsonResponse|array
     */
    private function getArtistItems(Request $request, string $artistId, string $type)
    {
        if (! in_array($type, $this->getArtistTypes)) {
            abort(404);
        }

        $isAudios = $type == $this->audiosByArtist;
        $offset = getPage($request) * $this->count;

        $cacheKey = sprintf('%s.%s', $type, $this->getCacheKeyForId($request, $artistId));
        $cachedResult = $this->getCache($cacheKey);

        if (! is_null($cachedResult)) {
            logger()->getArtistItemsCache($type, $artistId, $offset);

            return $isAudios ? $this->audiosResponse($request, $cachedResult, false) : okResponse($cachedResult, self::$SEARCH_BACKEND_ALBUMS);
        }

        $captchaParams = $this->getCaptchaParams($request);
        $params = [
            'access_token' => config('app.auth.tokens')[$this->accessTokenIndex],
            'artist_id'    => $artistId,
            'offset'       => $offset,
            'count'        => $this->count,
            'extended'     => 1,
        ];

        $response = as_json(vkClient()->get('method/audio.get'.ucfirst($type), [
            'query' => $params + $captchaParams,
        ]
        ));

        $error = $this->checkSearchResponseError($request, $response);
        if ($error) {
            return $error;
        }

        $data = $isAudios ? $this->parseAudioItems($response) : $response->response->items;

        $this->cacheResult($cacheKey, $data);
        logger()->getArtistItems($type, $artistId, $offset);

        return $isAudios ? $this->audiosResponse($request, $data) : okResponse($data, self::$SEARCH_BACKEND_ALBUMS);
    }
}
