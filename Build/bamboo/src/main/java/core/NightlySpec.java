package core;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

import java.util.ArrayList;

import com.atlassian.bamboo.specs.api.BambooSpec;
import com.atlassian.bamboo.specs.api.builders.BambooKey;
import com.atlassian.bamboo.specs.api.builders.Variable;
import com.atlassian.bamboo.specs.api.builders.plan.Job;
import com.atlassian.bamboo.specs.api.builders.plan.Plan;
import com.atlassian.bamboo.specs.api.builders.plan.Stage;
import com.atlassian.bamboo.specs.api.builders.plan.branches.BranchCleanup;
import com.atlassian.bamboo.specs.api.builders.plan.branches.PlanBranchManagement;
import com.atlassian.bamboo.specs.api.builders.project.Project;
import com.atlassian.bamboo.specs.builders.task.ScriptTask;
import com.atlassian.bamboo.specs.builders.trigger.ScheduledTrigger;
import com.atlassian.bamboo.specs.model.task.ScriptTaskProperties;
import com.atlassian.bamboo.specs.util.BambooServer;

/**
 * Core master nightly test plan.
 */
@BambooSpec
public class NightlySpec extends AbstractCoreSpec {

    protected static String planName = "Core master nightly";
    protected static String planKey = "GTN";

    protected int numberOfAcceptanceTestJobs = 8;
    protected int numberOfFunctionalMysqlJobs = 6;
    protected int numberOfFunctionalMssqlJobs = 16;
    protected int numberOfFunctionalPgsqlJobs = 6;
    protected int numberOfFunctionalSqliteJobs = 6;
    protected int numberOfUnitRandomOrderJobs = 4;

    /**
     * Run main to publish plan on Bamboo
     */
    public static void main(final String[] args) throws Exception {
        // By default credentials are read from the '.credentials' file.
        BambooServer bambooServer = new BambooServer(bambooServerName);
        bambooServer.publish(new NightlySpec().createPlan());
        bambooServer.publish(new NightlySpec().getDefaultPlanPermissions(projectKey, planKey));
    }

    /**
     * Core master pre-merge plan is in "TYPO3 core" project of bamboo
     */
    Project project() {
        return new Project().name(projectName).key(projectKey);
    }

    /**
     * Returns full Plan definition
     */
    Plan createPlan() {
        // PREPARATION stage
        ArrayList<Job> jobsPreparationStage = new ArrayList<Job>();
        jobsPreparationStage.add(this.getJobBuildLabels());
        Stage stagePreparation = new Stage("Preparation")
            .jobs(jobsPreparationStage.toArray(new Job[jobsPreparationStage.size()]));


        // MAIN stage
        ArrayList<Job> jobsMainStage = new ArrayList<Job>();

        jobsMainStage.add(this.getJobComposerValidate("PHP72"));

        jobsMainStage.add(this.getJobAcceptanceTestInstallMysql(0, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.add(this.getJobAcceptanceTestInstallPgsql(0, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.add(this.getJobAcceptanceTestInstallSqlite(0, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.addAll(this.getJobsAcceptanceTestsBackendMysql(0, this.numberOfAcceptanceTestJobs, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.add(this.getJobCglCheckFullCore(0, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.add(this.getJobIntegrationAnnotations(0, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.add(this.getJobIntegrationVarious(0, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.addAll(this.getJobsFunctionalTestsMysql(0, this.numberOfFunctionalMysqlJobs, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.addAll(this.getJobsFunctionalTestsMssql(0, this.numberOfFunctionalMssqlJobs, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.addAll(this.getJobsFunctionalTestsPgsql(0, this.numberOfFunctionalPgsqlJobs, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.addAll(this.getJobsFunctionalTestsSqlite(0, this.numberOfFunctionalSqliteJobs, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.add(this.getJobUnitJavaScript(0, "PHP72", this.getTaskComposerInstall("PHP72")));

        jobsMainStage.add(this.getJobLintPhp("PHP72"));

        jobsMainStage.add(this.getJobLintScssTs("PHP72"));

        jobsMainStage.add(this.getJobUnitPhp(0, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.add(this.getJobUnitDeprecatedPhp(0, "PHP72", this.getTaskComposerInstall("PHP72")));
        jobsMainStage.addAll(this.getJobUnitPhpRandom(0, this.numberOfUnitRandomOrderJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        Stage stageMainStage = new Stage("Main stage")
            .jobs(jobsMainStage.toArray(new Job[jobsMainStage.size()]));


        // COMPOSER UPDATE MAX stage
        ArrayList<Job> jobsComposerMaxStage = new ArrayList<Job>();

        jobsComposerMaxStage.add(this.getJobAcceptanceTestInstallMysql(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.add(this.getJobAcceptanceTestInstallPgsql(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.add(this.getJobAcceptanceTestInstallSqlite(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.addAll(this.getJobsAcceptanceTestsBackendMysql(1, this.numberOfAcceptanceTestJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.add(this.getJobCglCheckFullCore(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.add(this.getJobIntegrationAnnotations(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.add(this.getJobIntegrationVarious(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.addAll(this.getJobsFunctionalTestsMysql(1, this.numberOfFunctionalMysqlJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.addAll(this.getJobsFunctionalTestsMssql(1, this.numberOfFunctionalMssqlJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.addAll(this.getJobsFunctionalTestsPgsql(1, this.numberOfFunctionalPgsqlJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.addAll(this.getJobsFunctionalTestsSqlite(1, this.numberOfFunctionalSqliteJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.add(this.getJobUnitJavaScript(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        jobsComposerMaxStage.add(this.getJobUnitPhp(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.add(this.getJobUnitDeprecatedPhp(1, "PHP72", this.getTaskComposerUpdateMax("PHP72")));
        jobsComposerMaxStage.addAll(this.getJobUnitPhpRandom(1, this.numberOfUnitRandomOrderJobs, "PHP72", this.getTaskComposerUpdateMax("PHP72")));

        Stage stageComposerMaxStage = new Stage("Composer update max")
            .jobs(jobsComposerMaxStage.toArray(new Job[jobsComposerMaxStage.size()]));

        // COMPOSER UPDATE MIN stage
        ArrayList<Job> jobsComposerMinStage = new ArrayList<Job>();

        jobsComposerMinStage.add(this.getJobAcceptanceTestInstallMysql(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.add(this.getJobAcceptanceTestInstallPgsql(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.add(this.getJobAcceptanceTestInstallSqlite(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.addAll(this.getJobsAcceptanceTestsBackendMysql(2, this.numberOfAcceptanceTestJobs, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.add(this.getJobCglCheckFullCore(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.add(this.getJobIntegrationAnnotations(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.add(this.getJobIntegrationVarious(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.addAll(this.getJobsFunctionalTestsMysql(2, this.numberOfFunctionalMysqlJobs, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.addAll(this.getJobsFunctionalTestsMssql(2, this.numberOfFunctionalMssqlJobs, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.addAll(this.getJobsFunctionalTestsPgsql(2, this.numberOfFunctionalPgsqlJobs, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.addAll(this.getJobsFunctionalTestsSqlite(2, this.numberOfFunctionalSqliteJobs, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.add(this.getJobUnitJavaScript(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        jobsComposerMinStage.add(this.getJobUnitPhp(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.add(this.getJobUnitDeprecatedPhp(2, "PHP72", this.getTaskComposerUpdateMin("PHP72")));
        jobsComposerMinStage.addAll(this.getJobUnitPhpRandom(2, this.numberOfUnitRandomOrderJobs, "PHP72", this.getTaskComposerUpdateMin("PHP72")));

        Stage stageComposerMinStage = new Stage("Composer update min")
            .jobs(jobsComposerMinStage.toArray(new Job[jobsComposerMinStage.size()]));

        // Compile plan
        return new Plan(project(), planName, planKey)
            .description("Execute TYPO3 core master nightly tests. Auto generated! See Build/bamboo of core git repository.")
            .pluginConfigurations(this.getDefaultPlanPluginConfiguration())
            .stages(
                stagePreparation,
                stageMainStage,
                stageComposerMaxStage,
                stageComposerMinStage
            )
            .linkedRepositories("github TYPO3 TYPO3.CMS")
            .triggers(
                new ScheduledTrigger()
                    .name("Scheduled")
                    .description("daily at night")
                    // daily 03:23
                    .cronExpression("0 23 3 ? * *")
            )
            .variables(
                new Variable("changeUrl", ""),
                new Variable("patchset", "")
            )
            .planBranchManagement(
                new PlanBranchManagement()
                    .delete(new BranchCleanup())
                    .notificationForCommitters()
            );
    }
}
