<?php

/**
 * @copyright   Copyright (C) 2021-2025 Björn Rudner
 * @license     https://www.gnu.org/licenses/gpl-3.0.en.html
 * @version     2025-04-24
 *
 * iTop module definition file
 */

 /** @disregard P1009 Undefined type SetupWebPage */
SetupWebPage::AddModule(
    __FILE__, // Path to the current file, all other file names are relative to the directory containing this file
    'br-landscape/3.2.0',
    array(
        // Identification
        //
        'label' => 'Datamodel: System Landscape',
        'category' => 'business',

        // Setup
        //
        'dependencies' => array(
            'itop-structure/3.0.0',
            'itop-virtualization-mgmt/0.0.0'
        ),
        'mandatory' => false,
        'visible' => true,
        'installer' => 'SystemLandscapeInstaller',

        // Components
        //
        'datamodel' => array(),
        'webservice' => array(),
        'data.struct' => array(
            // add your 'structure' definition XML files here,
        ),
        'data.sample' => array(
            // add your sample data XML files here,
        ),

        // Documentation
        //
        'doc.manual_setup' => '', // hyperlink to manual setup documentation, if any
        'doc.more_information' => '', // hyperlink to more information, if any

        // Default settings
        //
        'settings' => array(
            // Module specific settings go here, if any
        ),
    )
);


if (!class_exists('SystemLandscapeInstaller')) {
    /**
     * Module installation handler
     */
    class SystemLandscapeInstaller extends ModuleInstallerAPI
    {
        public static function AfterDatabaseCreation(Config $oConfiguration, $sPreviousVersion, $sCurrentVersion)
        {
            // Create audit rules introduced in Version 1.0.0
            if (version_compare($sPreviousVersion, '1.0.0', '<')) {
                SetupLog::Info("|- Installing landscape from '$sPreviousVersion' to '$sCurrentVersion'. The extension comes with audit rules so corresponding objects will created into the DB...");

                if (MetaModel::IsValidClass('AuditRule')) {
                    // First, create audit category for Server mismatch
                    $oSearch = DBObjectSearch::FromOQL('SELECT AuditCategory WHERE name = "System Landscape Mismatch"');
                    $oSet = new DBObjectSet($oSearch);
                    $oAuditCategory = $oSet->Fetch();

                    if ($oAuditCategory === null) {
                        try {
                            $oAuditCategory = MetaModel::NewObject('AuditCategory', array(
                                'name' => 'System Landscape Mismatch',
                                'definition_set' => 'SELECT FunctionalCI',
                            ));
                            $oAuditCategory->DBWrite();
                            SetupLog::Info('|  |- AuditCategory "System Landscape Mismatch" created.');
                        } catch (Exception $oException) {
                            SetupLog::Info('|  |- Could not create AuditCategory. (Error: ' . $oException->getMessage() . ')');
                        }
                    } else {
                        SetupLog::Info('|  |- AuditCategory "System Landscape Mismatch" already existing! Weird as it is supposed to be created by this extension, but meh, will use it anyway!');
                    }

                    // Then, create audit rules
                    $aAuditRules = array(
                        array(
                            'name' => 'Server has other Landscape than Hypervisor',
                            'description' => 'Show all Servers that have configured other System Landscape then the corresponding Hypervisor',
                            'query' =>  "SELECT Server AS s\n" .
                                "JOIN Hypervisor AS h ON h.server_id = s.id\n" .
                                "WHERE h.system_landscape != s.system_landscape",
                            'valid_flag' => 'false',
                        ),
                        array(
                            'name' => 'Hypervisor has other Landscape than Farm',
                            'description' => 'Show all Hypervisors that have configured other System Landscape then the corresponding Farm',
                            'query' =>  "SELECT Hypervisor AS h\n" .
                                "JOIN Farm AS f ON h.farm_id = f.id\n" .
                                "WHERE h.system_landscape != f.system_landscape",
                            'valid_flag' => 'false',
                        ),
                        array(
                            'name' => 'FunctionalCI has other Landscape than ApplicationSolution',
                            'description' => 'Show all Servers, Farms, Hypervisors and VirtualMachines that have configured other System Landscape then the corresponding ApplicationSolution',
                            'query' =>  "SELECT FunctionalCI AS f\n" .
                                "JOIN lnkApplicationSolutionToFunctionalCI AS lnk ON lnk.functionalci_id = f.id\n" .
                                "JOIN ApplicationSolution AS a ON lnk.applicationsolution_id = a.id\n" .
                                "WHERE lnk.functionalci_id_finalclass_recall != 'ApplicationSolution'\n" .
                                "AND lnk.functionalci_id_finalclass_recall IN ('Server', 'Farm', 'Hypervisor', 'VirtualMachine')\n" .
                                "AND a.system_landscape != f.system_landscape",
                            'valid_flag' => 'false',
                        ),
                    );
                    foreach ($aAuditRules as $aAuditRule) {
                        try {
                            $oAuditRule = MetaModel::NewObject('AuditRule', $aAuditRule);
                            $oAuditRule->Set('category_id', $oAuditCategory->GetKey());
                            $oAuditRule->DBWrite();
                            SetupLog::Info('|  |- AuditRule "' . $aAuditRule['name'] . '" created.');
                        } catch (Exception $oException) {
                            SetupLog::Info('|  |- Could not create AuditRule "' . $aAuditRule['name'] . '". (Error: ' . $oException->getMessage() . ')');
                        }
                    }
                }
            }

            // Introduce AuditDomain in Version 3.2.0
            if (version_compare($sPreviousVersion, '3.2.0', '<')) {
                SetupLog::Info("|- Installing System Landscape from '$sPreviousVersion' to '$sCurrentVersion'. Updating AuditDomain and lnkAuditCategoryToAuditDomain ...");

                $iAuditDomainId = 0;
                $iAuditCategoryId = 0;

                if (MetaModel::IsValidClass('AuditDomain')) {
                    // First, create audit category for Server mismatch
                    $oSearch = DBObjectSearch::FromOQL('SELECT AuditDomain WHERE name = "System Landscape"');
                    $oSet = new DBObjectSet($oSearch);
                    $oAuditDomain = $oSet->Fetch();

                    if ($oAuditDomain === null) {
                        try {
                            $oAuditDomain = MetaModel::NewObject('AuditDomain', array(
                                'name' => 'System Landscape',
                                'description' => 'Audit System Landscapes defined in the CMDB',
                            ));
                            $oAuditDomain->DBWrite();
                            SetupLog::Info('|  |- AuditDomain "System Landscape" created.');
                        } catch (Exception $oException) {
                            SetupLog::Info('|  |- Could not create AuditDomain. (Error: ' . $oException->getMessage() . ')');
                        }
                    } else {
                        SetupLog::Info('|  |- AuditDomain "System Landscape" already existing! We will use it!');
                    }

                    // Link AuditDomain with AuditCategory: System Landscape Mismatch
                    $oSearch = DBObjectSearch::FromOQL('SELECT AuditCategory WHERE name = "System Landscape Mismatch"');
                    $oSet = new DBObjectSet($oSearch);
                    $oAuditCategory = $oSet->Fetch();

                    $iAuditDomainId = ($oAuditDomain !== null) ? $oAuditDomain->GetKey() : 0;
                    $iAuditCategoryId = ($oAuditCategory !== null) ? $oAuditCategory->GetKey() : 0;

                    if ($iAuditDomainId > 0 && $iAuditCategoryId > 0) {
                        try {
                            $oAuditLink = MetaModel::NewObject('lnkAuditCategoryToAuditDomain', array(
                                'domain_id' => $iAuditDomainId,
                                'category_id' => $iAuditCategoryId,
                            ));
                            $oAuditLink->DBWrite();
                            SetupLog::Info('|  |- AuditLink created.');
                        } catch (Exception $oException) {
                            SetupLog::Info('|  |- Could not create AuditLink. (Error: ' . $oException->getMessage() . ')');
                        }
                    }
                }
            }
        }
    }
};
