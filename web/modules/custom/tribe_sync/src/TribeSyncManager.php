<?php

namespace Drupal\tribe_sync;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Runs the ArcGIS and EPA tribe synchronization with change tracking.
 */
class TribeSyncManager {

  /**
   * Syncs tribe data from remote sources.
   */
  public function sync(bool $dry_run = FALSE): array {
    $arcgis_url = 'https://services1.arcgis.com/UxqqIfhng71wUT9x/arcgis/rest/services/TribalLeadership_Directory/FeatureServer/0/query?where=1%3D1&outFields=*&f=geojson';
    $epa_url = 'https://cdxapi.epa.gov/oms-tribes-rest-services/api/v1/tribeDetails';

    // Initialize Counters for Tracking Reports
    $created_count = 0;
    $updated_count = 0;
    $id_shift_count = 0;
    $shifted_tribes = [];

    $simplify = function ($name) {
      if (empty($name)) {
        return '';
      }
      $name = strtolower($name);
      $name = preg_replace('/\s*\(.*?\)\s*/', '', $name);
      $name = preg_replace('/[^a-z0-9]/', '', $name);
      $noise = ['tribe', 'village', 'native', 'community', 'of', 'the', 'council', 'indian', 'alaska', 'association', 'aka'];
      foreach ($noise as $word) {
        $name = str_replace($word, '', $name);
      }
      return $name;
    };

    $get_state_code = function ($name) {
      $states = [
        'alabama' => 'AL', 'alaska' => 'AK', 'american samoa' => 'AS', 'arizona' => 'AZ',
        'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT',
        'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA',
        'guam' => 'GU', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN',
        'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME',
        'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
        'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM',
        'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
        'oklahoma' => 'OK', 'or_egon' => 'OR', 'pennsylvania' => 'PA', 'puerto rico' => 'PR',
        'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN',
        'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virgin islands' => 'VI',
        'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
      ];
      $clean = strtolower(trim($name ?? ''));
      if (strlen($clean) === 2) {
        return strtoupper($clean);
      }
      return $states[$clean] ?? strtoupper($clean);
    };

    $epa_res = \Drupal::httpClient()->get($epa_url);
    $epa_data = json_decode($epa_res->getBody(), TRUE);

    $epa_lookup_code = [];
    $epa_lookup_name = [];

    foreach ($epa_data as $item) {
      $b_code = $this->normalizeBiaCode($item['currentBIATribalCode'] ?? '');
      $payload = [
        'epa_id' => $item['epaTribalInternalId'] ?? NULL,
        'aka' => $item['currentName'] ?? '',
        'tribal_land_code' => $item['currentBIATribalCode'] ?? NULL,
      ];

      if ($b_code) {
        $epa_lookup_code[$b_code] = $payload;
      }

      $p_key = $simplify($item['currentName'] ?? '');
      if ($p_key) {
        $epa_lookup_name[$p_key] = $payload;
      }

      if (!empty($item['names']) && is_array($item['names'])) {
        foreach ($item['names'] as $name_obj) {
          $a_key = $simplify($name_obj['name'] ?? '');
          if ($a_key) {
            $epa_lookup_name[$a_key] = $payload;
          }
        }
      }
    }

    $arcgis_res = \Drupal::httpClient()->get($arcgis_url);
    $arcgis_json = json_decode($arcgis_res->getBody(), TRUE);
    $features = $arcgis_json['features'] ?? [];

    if ($dry_run) {
      $rows = [];
      foreach (array_slice($features, 0, 10) as $feature) {
        $p = array_change_key_case($feature['properties'], CASE_LOWER);
        $rows[] = [$p['tribefullname'] ?? '', $p['jobtitle'] ?? 'N/A', $get_state_code($p['state'] ?? '')];
      }
      return [
        'mode' => 'dry-run',
        'rows' => $rows,
        'processed' => count($rows),
      ];
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $processed = 0;

    foreach ($features as $feature) {
      $p = array_change_key_case($feature['properties'], CASE_LOWER);
      $global_id = $p['globalid'] ?? NULL;
      if (!$global_id) {
        continue;
      }

      // 1. First, try matching the incoming ArcGIS Global ID
      $existing = $storage->getQuery()
        ->condition('type', 'tribe')
        ->condition('field_global_id', $global_id)
        ->accessCheck(FALSE)
        ->execute();

      $id_shifted = FALSE;

      // 2. TRIPLE FAIL-SAFE: If ArcGIS changed the Global ID, check stable identifiers
      if (empty($existing)) {
        $norm_bia = $this->normalizeBiaCode($p['currentbiatribalcode'] ?? $p['biacode'] ?? '');
        
        if (!empty($norm_bia)) {
          // Look up by the immutable BIA Integer Code
          $existing = $storage->getQuery()
            ->condition('type', 'tribe')
            ->condition('field_tribal_land_code', $norm_bia) // Updated to correct machine name
            ->accessCheck(FALSE)
            ->execute();
          
          if (!empty($existing)) {
            $id_shifted = TRUE;
          }
        }
        
        // 3. Last resort fallback: Check against the core node TITLE
        if (empty($existing) && !empty($p['tribefullname'])) {
          $existing = $storage->getQuery()
            ->condition('type', 'tribe')
            ->condition('title', $p['tribefullname']) // Exact match against the node's real title
            ->accessCheck(FALSE)
            ->execute();
          
          if (!empty($existing)) {
            $id_shifted = TRUE;
          }
        }
      }

      // Evaluate Node Lifecycle & Log Structural Schema Mismatch/Changes
      if (!empty($existing)) {
        $node = $storage->load(reset($existing));
        
        if ($id_shifted) {
          $id_shift_count++;
          $old_id = $node->get('field_global_id')->value ?? 'N/A';
          $tribe_name = $p['tribefullname'] ?? 'Unknown Tribe';
          $shifted_tribes[] = "{$tribe_name} (GlobalID changed from '{$old_id}' to '{$global_id}')";
          
          // Log systemic warnings to Drupal's Recent Log Messages admin watchdogs
          \Drupal::logger('tribe_sync')->warning('ArcGIS ID Shift detected for %tribe. GlobalID mutated from %old to %new.', [
            '%tribe' => $tribe_name,
            '%old' => $old_id,
            '%new' => $global_id,
          ]);
        } else {
          $updated_count++;
        }
      } else {
        $node = Node::create(['type' => 'tribe']);
        $created_count++;
      }

      // Always reset the dynamic/mutating keys to guarantee changes register past standard cache
      $node->set('field_global_id', $global_id);
      $node->set('field_object_id', $p['objectid'] ?? NULL);

      $norm_bia = $this->normalizeBiaCode($p['currentbiatribalcode'] ?? $p['biacode'] ?? '');
      $simple_arc = $simplify($p['tribefullname'] ?? '');
      $epa_info = $epa_lookup_code[$norm_bia] ?? $epa_lookup_name[$simple_arc] ?? ['epa_id' => NULL, 'aka' => '', 'tribal_land_code' => NULL];

      $p_state_code = $get_state_code($p['state'] ?? '');
      $m_state_raw = !empty($p['mailingstate']) ? $p['mailingstate'] : ($p['state'] ?? '');
      $m_state_code = $get_state_code($m_state_raw);

      $node->setTitle(mb_strimwidth($p['tribefullname'] ?? '', 0, 255, '...'));
      $node->set('field_job_title', $p['jobtitle'] ?? '');
      $node->set('field_first_name', $p['firstname'] ?? '');
      $node->set('field_last_name', $p['lastname'] ?? '');
      $node->set('field_phone', $p['phone'] ?? '');

      if (!empty($p['email'])) {
        $emails = array_filter(array_map('trim', explode(';', $p['email'])));
        $node->set('field_email', array_values($emails));
      }

      if (!empty($p['website'])) {
        $url = (strpos($p['website'], 'http') === 0) ? $p['website'] : 'http://' . $p['website'];
        $node->set('field_website', ['uri' => $url, 'title' => 'Website']);
      }

      $node->set('field_physical_address', [
        'country_code' => 'US',
        'address_line1' => $p['physicaladdress'] ?? '',
        'locality' => $p['city'] ?? '',
        'administrative_area' => $p_state_code,
        'postal_code' => $p['zipcode'] ?? '',
      ]);

      $node->set('field_mailing_address', [
        'country_code' => 'US',
        'address_line1' => !empty($p['mailingaddress']) ? $p['mailingaddress'] : ($p['physicaladdress'] ?? ''),
        'locality' => !empty($p['mailingcity']) ? $p['mailingcity'] : ($p['city'] ?? ''),
        'administrative_area' => $m_state_code,
        'postal_code' => !empty($p['mailingzipcode']) ? $p['mailingzipcode'] : ($p['zipcode'] ?? ''),
      ]);

      // EPA / BIA IDs
      $node->set('field_epa_id', $epa_info['epa_id']);
      $node->set('field_aka', mb_strimwidth($epa_info['aka'], 0, 255, '...'));
      $node->set('field_tribal_land_code', $norm_bia); // <--- Clean 3-digit code saved here
      $node->set('field_object_id', $p['objectid'] ?? NULL);

      if (!empty($p['biaregion'])) {
        $node->set('field_bia_region', $this->ensureTerm($p['biaregion'], 'regions_bia'));
      }
      if (!empty($p['alaskasubsistenceregion'])) {
        $node->set('field_alaska_subsistence_region', $this->ensureTerm($p['alaskasubsistenceregion'], 'alaska_subsistence_region'));
      }
      if (!empty($p['ancsaregion'])) {
        $node->set('field_ancsa_region', $this->ensureTerm($p['ancsaregion'], 'ancsa_region'));
      }
      if (!empty($p['biaagency'])) {
        $node->set('field_bia_agency', $this->ensureTerm($p['biaagency'], 'bia_agency'));
      }
      if (!empty($p['blmregion'])) {
        $node->set('field_bureau_of_land_management', $this->ensureTerm($p['blmregion'], 'blm_state_offices'));
      }
      if (!empty($p['fwsregion'])) {
        $node->set('field_fws_regions', $this->ensureTerm($p['fwsregion'], 'fws_regions'));
      }
      if (!empty($p['lartype']) && $p['lartype'] !== 'Null') {
        $node->set('field_lar_type', $this->ensureTerm($p['lartype'], 'lar_type'));
      }
      if (!empty($p['npsregion'])) {
        $node->set('field_nps_unified_regions', $this->ensureTerm($p['npsregion'], 'nps_unified_regions'));
      }
      if (!empty($p['longitude']) && !empty($p['latitude'])) {
        $node->set('field_location', "POINT ({$p['longitude']} {$p['latitude']})");
      }

      $node->save();
      $storage->resetCache([$node->id()]);
      $processed++;
    }

    return [
      'mode' => 'sync',
      'processed' => $processed,
      'total' => count($features),
      'created' => $created_count,
      'updated' => $updated_count,
      'id_shifts' => $id_shift_count,
      'shifted_details' => $shifted_tribes,
    ];
  }

  /**
   * Normalizes a BIA code down to its last three digits.
   */
  protected function normalizeBiaCode($rawCode) {
    if (empty($rawCode)) {
      return NULL;
    }
    $numeric = preg_replace('/[^0-9]/', '', $rawCode);
    return !empty($numeric) ? (int) substr($numeric, -3) : NULL;
  }

  /**
   * Loads or creates a taxonomy term.
   */
  protected function ensureTerm($name, $vid) {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['name' => $name, 'vid' => $vid]);
    if ($terms) {
      return reset($terms)->id();
    }
    $term = Term::create(['name' => $name, 'vid' => $vid]);
    $term->save();
    return $term->id();
  }

}