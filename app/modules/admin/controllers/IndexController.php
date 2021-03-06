<?php
namespace Modules\Admin\Controllers;

class IndexController extends BaseController
{
    public function permissions()
    {
        return $this->acl->canSeeAdminDashboard();
    }

    /**
     * Main display.
     */
    public function indexAction()
    {
        // Inject all stations.
        $stations = \Entity\Station::fetchAll();
        $this->view->stations = $stations;

        // Inject all podcasts.
        $podcasts = \Entity\Podcast::fetchAll();
        $this->view->podcasts = $podcasts;

        // Pull cached statistic charts if available.
        $metrics = \DF\Cache::get('admin_metrics');

        if (!$metrics)
        {
            // Statistics by day.
            $influx = $this->di->get('influx');
            $station_averages = array();
            $network_data = array(
                'PVL Network' => array(
                    'ranges' => array(),
                    'averages' => array(),
                ),
            );

            $daily_stats = $influx->setDatabase('pvlive_stations')->query('SELECT * FROM /1d.*/ WHERE time > now() - 180d', 'm');

            foreach($daily_stats as $stat_series => $stat_rows)
            {
                $series_split = explode('.', $stat_series);

                if ($series_split[1] == 'all')
                {
                    $network_name = 'PVL Network';
                    foreach($stat_rows as $stat_row)
                    {
                        $network_data[$network_name]['ranges'][$stat_row['time']] = array($stat_row['time'], $stat_row['min'], $stat_row['max']);
                        $network_data[$network_name]['averages'][$stat_row['time']] = array($stat_row['time'], round($stat_row['value'], 2));
                    }
                }
                else
                {
                    $station_id = $series_split[2];
                    foreach($stat_rows as $stat_row)
                    {
                        $station_averages[$station_id][$stat_row['time']] = array($stat_row['time'], round($stat_row['value'], 2));
                    }
                }
            }

            $network_metrics = array();
            foreach ($network_data as $network_name => $data_charts) {
                if (isset($data_charts['ranges'])) {
                    $metric_row = new \stdClass;
                    $metric_row->name = $network_name . ' Listener Range';
                    $metric_row->type = 'arearange';

                    ksort($data_charts['ranges']);
                    $metric_row->data = array_values($data_charts['ranges']);

                    $network_metrics[] = $metric_row;
                }

                if (isset($data_charts['averages'])) {
                    $metric_row = new \stdClass;
                    $metric_row->name = $network_name . ' Daily Average';
                    $metric_row->type = 'spline';

                    ksort($data_charts['averages']);
                    $metric_row->data = array_values($data_charts['averages']);

                    $network_metrics[] = $metric_row;
                }
            }

            $station_metrics = array();

            foreach ($stations as $station) {
                $station_id = $station['id'];

                if (isset($station_averages[$station_id])) {
                    $series_obj = new \stdClass;
                    $series_obj->name = $station['name'];
                    $series_obj->type = 'spline';

                    ksort($station_averages[$station_id]);
                    $series_obj->data = array_values($station_averages[$station_id]);
                    $station_metrics[] = $series_obj;
                }
            }

            // Podcast and API Call Metrics
            $analytics_raw = $influx->setDatabase('pvlive_analytics')->query('SELECT * FROM /1h.*/', 'm');

            $raw_metrics = array();

            foreach($analytics_raw as $series_name => $series_stats)
            {
                $series_parts = explode('.', $series_name);

                if ($series_parts[1] == 'api_calls')
                    $metric_section = 'api';
                else
                    $metric_section = 'podcast';

                foreach($series_stats as $stat_row)
                {
                    if (!isset($raw_metrics[$metric_section][$stat_row['time']]))
                        $raw_metrics[$metric_section][$stat_row['time']] = 0;

                    $raw_metrics[$metric_section][$stat_row['time']] += $stat_row['count'];
                }
            }

            // Reformat for highcharts.
            $refined_metrics = array();
            foreach($raw_metrics as $metric_type => $metric_rows)
            {
                ksort($metric_rows);

                foreach($metric_rows as $row_timestamp => $row_value)
                    $refined_metrics[$metric_type][] = array($row_timestamp, $row_value);
            }

            // API call object
            $series_obj = new \stdClass;
            $series_obj->name = 'API Calls';
            $series_obj->type = 'spline';
            $series_obj->data = $refined_metrics['api'];
            $api_metrics = array($series_obj);

            // Podcast object
            $series_obj = new \stdClass;
            $series_obj->name = 'Podcast Clicks';
            $series_obj->type = 'spline';
            $series_obj->data = $refined_metrics['podcast'];
            $podcast_metrics = array($series_obj);

            $metrics = array(
                'network'   => json_encode($network_metrics),
                'station'   => json_encode($station_metrics),
                'api'       => json_encode($api_metrics),
                'podcast'   => json_encode($podcast_metrics),
            );

            \DF\Cache::save($metrics, 'admin_metrics', array(), 600);
        }

        $this->view->metrics = $metrics;

        // Synchronization statuses
        if ($this->acl->isAllowed('administer all'))
            $this->view->sync_times = \PVL\SyncManager::getSyncTimes();

        // PVLNode service stats.
        $this->view->pvlnode_stats = \PVL\Service\PvlNode::fetch();
    }

    public function syncAction()
    {
        $this->acl->checkPermission('administer all');

        $this->doNotRender();

        \PVL\Debug::setEchoMode(TRUE);
        \PVL\Debug::startTimer('sync_task');

        $type = $this->getParam('type', 'nowplaying');
        switch($type)
        {
            case "long":
                \PVL\SyncManager::syncLong();
            break;

            case "medium":
                \PVL\SyncManager::syncMedium();
            break;

            case "short":
                \PVL\SyncManager::syncShort();
            break;

            case "nowplaying":
            default:
                $segment = $this->getParam('segment', 1);
                define('NOWPLAYING_SEGMENT', $segment);

                \PVL\SyncManager::syncNowplaying(true);
            break;
        }

        \PVL\Debug::endTimer('sync_task');
        \PVL\Debug::log('Sync task complete. See log above.');
    }
}