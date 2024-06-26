<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\JobQueueBundle\Entity\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Proxy;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use JMS\JobQueueBundle\Retry\ExponentialRetryScheduler;
use JMS\JobQueueBundle\Retry\RetryScheduler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JobManager
{
    private EventDispatcherInterface $dispatcher;
    private EntityManagerInterface $entityManager;
    private RetryScheduler $retryScheduler;
    
    public function __construct(EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher, RetryScheduler $retryScheduler)
    {
        $this->entityManager = $entityManager;
        $this->dispatcher = $eventDispatcher;
        $this->retryScheduler = $retryScheduler;
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findJob($command, array $args = array())
    {
        return $this->entityManager->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args")
            ->setParameter('command', $command)
            ->setParameter('args', $args, ArrayParameterType::STRING)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * @throws NonUniqueResultException
     */
    public function getJob($command, array $args = array())
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        throw new \RuntimeException(sprintf('Found no job for command "%s" with args "%s".', $command, json_encode($args)));
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getOrCreateIfNotExists($command, array $args = array())
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        $job = new Job($command, $args, false);
        $this->entityManager->persist($job);
        $this->entityManager->flush($job);

        $firstJob = $this->entityManager->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args ORDER BY j.id ASC")
             ->setParameter('command', $command)
             ->setParameter('args', $args, 'json_array')
             ->setMaxResults(1)
             ->getSingleResult();

        if ($firstJob === $job) {
            $job->setState(Job::STATE_PENDING);
            $this->entityManager->persist($job);
            $this->entityManager->flush($job);

            return $job;
        }

        $this->entityManager->remove($job);
        $this->entityManager->flush($job);

        return $firstJob;
    }

    public function findStartableJob($workerName, array &$excludedIds = array(), $excludedQueues = array(), $restrictedQueues = array())
    {
        while (null !== $job = $this->findPendingJob($excludedIds, $excludedQueues, $restrictedQueues)) {
            if ($job->isStartable() && $this->acquireLock($workerName, $job)) {
                return $job;
            }

            $excludedIds[] = $job->getId();

            // We do not want to have non-startable jobs floating around in
            // cache as they might be changed by another process. So, better
            // re-fetch them when they are not excluded anymore.
            $this->entityManager->detach($job);
        }

        return null;
    }

    /**
     * @throws Exception
     */
    private function acquireLock($workerName, Job $job): bool
    {
        $affectedRows = $this->entityManager->getConnection()->executeStatement(
            "UPDATE jms_jobs SET workerName = :worker WHERE id = :id AND workerName IS NULL",
            array(
                'worker' => $workerName,
                'id' => $job->getId(),
            )
        );

        if ($affectedRows > 0) {
            $job->setWorkerName($workerName);

            return true;
        }

        return false;
    }

    public function findAllForRelatedEntity($relatedEntity)
    {
        list($relClass, $relId) = $this->getRelatedEntityIdentifier($relatedEntity);

        $rsm = new ResultSetMappingBuilder($this->entityManager);
        $rsm->addRootEntityFromClassMetadata('JMSJobQueueBundle:Job', 'j');

        return $this->entityManager->createNativeQuery("SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId", $rsm)
                    ->setParameter('relClass', $relClass)
                    ->setParameter('relId', $relId)
                    ->getResult();
    }

    public function findOpenJobForRelatedEntity($command, $relatedEntity)
    {
        return $this->findJobForRelatedEntity($command, $relatedEntity, array(Job::STATE_RUNNING, Job::STATE_PENDING, Job::STATE_NEW));
    }

    /**
     * @throws \ReflectionException
     * @throws NonUniqueResultException
     * @throws MappingException
     */
    public function findJobForRelatedEntity($command, $relatedEntity, array $states = array())
    {
        list($relClass, $relId) = $this->getRelatedEntityIdentifier($relatedEntity);

        $rsm = new ResultSetMappingBuilder($this->entityManager);
        $rsm->addRootEntityFromClassMetadata('JMSJobQueueBundle:Job', 'j');

        $sql = "SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId AND j.command = :command";
        $params = new ArrayCollection();
        $params->add(new Parameter('command', $command));
        $params->add(new Parameter('relClass', $relClass));
        $params->add(new Parameter('relId', $relId));

        if ( ! empty($states)) {
            $sql .= " AND j.state IN (:states)";
            $params->add(new Parameter('states', $states, Connection::PARAM_STR_ARRAY));
        }

        return $this->entityManager->createNativeQuery($sql, $rsm)
                   ->setParameters($params)
                   ->getOneOrNullResult();
    }

    /**
     * @throws \ReflectionException
     * @throws MappingException
     */
    private function getRelatedEntityIdentifier($entity): array
    {
        if ( ! is_object($entity)) {
            throw new \RuntimeException('$entity must be an object.');
        }

        if ($entity instanceof Proxy) {
            $entity->__load();
        }

        $relClass = ClassUtils::getClass($entity);
        $relId = $this->entityManager->getMetadataFactory()
                    ->getMetadataFor($relClass)->getIdentifierValues($entity);
        asort($relId);

        if ( ! $relId) {
            throw new \InvalidArgumentException(sprintf('The identifier for entity of class "%s" was empty.', $relClass));
        }

        return array($relClass, json_encode($relId));
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findPendingJob(array $excludedIds = array(), array $excludedQueues = array(), array $restrictedQueues = array())
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('j')->from('JMSJobQueueBundle:Job', 'j')
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.id', 'ASC');

        $conditions = array();

        $conditions[] = $qb->expr()->isNull('j.workerName');

        $conditions[] = $qb->expr()->lt('j.executeAfter', ':now');
        $qb->setParameter(':now', new \DateTime(), 'datetime');

        $conditions[] = $qb->expr()->eq('j.state', ':state');
        $qb->setParameter('state', Job::STATE_PENDING);

        if ( ! empty($excludedIds)) {
            $conditions[] = $qb->expr()->notIn('j.id', ':excludedIds');
            $qb->setParameter('excludedIds', $excludedIds, Connection::PARAM_INT_ARRAY);
        }

        if ( ! empty($excludedQueues)) {
            $conditions[] = $qb->expr()->notIn('j.queue', ':excludedQueues');
            $qb->setParameter('excludedQueues', $excludedQueues, Connection::PARAM_STR_ARRAY);
        }

        if ( ! empty($restrictedQueues)) {
            $conditions[] = $qb->expr()->in('j.queue', ':restrictedQueues');
            $qb->setParameter('restrictedQueues', $restrictedQueues, Connection::PARAM_STR_ARRAY);
        }

        $qb->where(call_user_func_array(array($qb->expr(), 'andX'), $conditions));

        return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }

    /**
     * @throws OptimisticLockException
     * @throws ORMException
     * @throws Exception
     */
    public function closeJob(Job $job, $finalState)
    {
        $this->entityManager->getConnection()->beginTransaction();
        try {
            $visited = array();
            $this->closeJobInternal($job, $finalState, $visited);
            $this->entityManager->flush();
            $this->entityManager->getConnection()->commit();

            // Clean-up entity manager to allow for garbage collection to kick in.
            foreach ($visited as $job) {
                // If the job is an original job which is now being retried, let's
                // not remove it just yet.
                if ( ! $job->isClosedNonSuccessful() || $job->isRetryJob()) {
                    continue;
                }

                $this->entityManager->detach($job);
            }
        } catch (\Exception $ex) {
            $this->entityManager->getConnection()->rollback();

            throw $ex;
        }
    }

    private function closeJobInternal(Job $job, $finalState, array &$visited = array())
    {
        if (in_array($job, $visited, true)) {
            return;
        }
        $visited[] = $job;

        if ($job->isInFinalState()) {
            return;
        }

        if ($job->isRetryJob() || 0 === count($job->getRetryJobs())) {
            $event = new StateChangeEvent($job, $finalState);
            $this->dispatcher->dispatch($event, 'jms_job_queue.job_state_change');
            $finalState = $event->getNewState();
        }

        switch ($finalState) {
            case Job::STATE_CANCELED:
                $job->setState(Job::STATE_CANCELED);
                $this->entityManager->persist($job);

                if ($job->isRetryJob()) {
                    $this->closeJobInternal($job->getOriginalJob(), Job::STATE_CANCELED, $visited);

                    return;
                }

                foreach ($this->findIncomingDependencies($job) as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FAILED:
            case Job::STATE_TERMINATED:
            case Job::STATE_INCOMPLETE:
                if ($job->isRetryJob()) {
                    $job->setState($finalState);
                    $this->entityManager->persist($job);

                    $this->closeJobInternal($job->getOriginalJob(), $finalState);

                    return;
                }

                // The original job has failed, and we are allowed to retry it.
                if ($job->isRetryAllowed()) {
                    $retryJob = new Job($job->getCommand(), $job->getArgs(), true, $job->getQueue(), $job->getPriority());
                    $retryJob->setMaxRuntime($job->getMaxRuntime());

                    if ($this->retryScheduler === null) {
                        $this->retryScheduler = new ExponentialRetryScheduler(5);
                    }

                    $retryJob->setExecuteAfter($this->retryScheduler->scheduleNextRetry($job));

                    $job->addRetryJob($retryJob);
                    $this->entityManager->persist($retryJob);
                    $this->entityManager->persist($job);

                    return;
                }

                $job->setState($finalState);
                $this->entityManager->persist($job);

                // The original job has failed, and no retries are allowed.
                foreach ($this->findIncomingDependencies($job) as $dep) {
                    // This is a safe-guard to avoid blowing up if there is a database inconsistency.
                    if ( ! $dep->isPending() && ! $dep->isNew()) {
                        continue;
                    }

                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FINISHED:
                if ($job->isRetryJob()) {
                    $job->getOriginalJob()->setState($finalState);
                    $this->entityManager->persist($job->getOriginalJob());
                }
                $job->setState($finalState);
                $this->entityManager->persist($job);

                return;

            default:
                throw new \LogicException(sprintf('Non allowed state "%s" in closeJobInternal().', $finalState));
        }
    }

    /**
     * @return Job[]
     */
    public function findIncomingDependencies(Job $job)
    {
        $jobIds = $this->getJobIdsOfIncomingDependencies($job);
        if (empty($jobIds)) {
            return array();
        }

        return $this->entityManager->createQuery("SELECT j, d FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE j.id IN (:ids)")
                    ->setParameter('ids', $jobIds)
                    ->getResult();
    }

    /**
     * @return Job[]
     * @throws Exception
     */
    public function getIncomingDependencies(Job $job)
    {
        $jobIds = $this->getJobIdsOfIncomingDependencies($job);
        if (empty($jobIds)) {
            return array();
        }

        return $this->entityManager->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.id IN (:ids)")
                    ->setParameter('ids', $jobIds)
                    ->getResult();
    }

    /**
     * @throws Exception
     */
    private function getJobIdsOfIncomingDependencies(Job $job): array
    {
        return $this->entityManager->getConnection()
            ->executeQuery("SELECT source_job_id FROM jms_job_dependencies WHERE dest_job_id = :id", array('id' => $job->getId()))
            ->fetchFirstColumn();
    }

    public function findLastJobsWithError($nbJobs = 10)
    {
        return $this->entityManager->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.state IN (:errorStates) AND j.originalJob IS NULL ORDER BY j.closedAt DESC")
                    ->setParameter('errorStates', array(Job::STATE_TERMINATED, Job::STATE_FAILED))
                    ->setMaxResults($nbJobs)
                    ->getResult();
    }

    public function getAvailableQueueList(): array
    {
        $queues =  $this->entityManager->createQuery("SELECT DISTINCT j.queue FROM JMSJobQueueBundle:Job j WHERE j.state IN (:availableStates)  GROUP BY j.queue")
            ->setParameter('availableStates', array(Job::STATE_RUNNING, Job::STATE_NEW, Job::STATE_PENDING))
            ->getResult();

        $newQueueArray = array();

        foreach($queues as $queue) {
            $newQueue = $queue['queue'];
            $newQueueArray[] = $newQueue;
        }

        return $newQueueArray;
    }


    /**
     * @throws NonUniqueResultException
     */
    public function getAvailableJobsForQueueCount($jobQueue): int
    {
        $result = $this->entityManager->createQuery("SELECT j.queue FROM JMSJobQueueBundle:Job j WHERE j.state IN (:availableStates) AND j.queue = :queue")
            ->setParameter('availableStates', array(Job::STATE_RUNNING, Job::STATE_NEW, Job::STATE_PENDING))
            ->setParameter('queue', $jobQueue)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return count($result);
    }
    
    private function getJobManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
