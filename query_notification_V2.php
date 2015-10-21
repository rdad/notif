<?php

use Entity\Entity;

/**
 * Query notification : Model des notifications
 * Permet de faire des requêtes sur la table notification
 *
 * Utilisé sur la page notification et par l'API notification
 * @package Notification
 * @category Modèle (/models)
 * 
 */
class Query_notification extends CI_Model
{
    /**
     * @var integer Nombre de notification autorisé par page 
     */
    private $by_page;
    
    function __construct() {
        
        parent::__construct();       
        $this->by_page = $this->config->item('notification_nb_by_page');
    }
    
    
    public function get_by_target($target_name, $target_id, $type_id, $nb_by_page = 10, $page = 0){
        
        $qb       = $this->doctrine->em->createQueryBuilder();
        
        $qb->from('Entity\Notification', 'n')
                ->select('n')
                ->where($qb->expr()->eq('n.type', ':type'))
                ->andWhere($qb->expr()->eq('n.'.$target_name, ':target_id'))
                ->orderBy('n.id', 'DESC')
                ->setParameter('type', $type_id)
                ->setParameter('target_id', $target_id)
                ->setMaxResults($nb_by_page)
                ->setFirstResult($page * $nb_by_page);
     
        //if($page>0)   $qb->setFirstResult($page * $nb_by_page + 1);      
        //$qb->setFirstResult(5);
               
        $query = $qb->getQuery();
        
        $paginator = new Doctrine\ORM\Tools\Pagination\Paginator($query, false);
        //if($page>0) echo $query->getSql();
        //$query->useResultCache(FALSE);
        return $paginator;
        //return $query->getResult(); 
    }

    
    /**
     * Renvoie une Collection d'entité Like
     * @param \Entity\User $user
     * @param integer $page Pagination des résultats (utilisé par l'API social/like_list)
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_like($user, $page=0){
        
        $qb       = $this->doctrine->em->createQueryBuilder();
        
        $qb->from('Entity\Notification', 'n')
                ->select('n')
                ->where('n.author = ?1')
                ->andWhere('n.type = ?2')
                /*->andWhere('n.target_group = ?3')
                ->andWhere('n.target_event = ?3')*/
                ->orderBy('n.id', 'DESC')
                ->setParameter(1,$user)
                ->setParameter(2, \Entity\Notification::LIKE)
                //->setParameter(3, null)
                ->setMaxResults($this->by_page);
     
        if($page>0)   $qb->setFirstResult( ($page * $this->by_page) + 1 );
               
        $query = $qb->getQuery();
        $query->useResultCache(FALSE);

        return $query->getResult(); 
    }
    
     private function get_base_query($user){
        
        $qb                 = $this->doctrine->em->createQueryBuilder();
        $my_like_post_qb    = $this->doctrine->em->createQueryBuilder();
        $my_share_post_qb   = $this->doctrine->em->createQueryBuilder();
        $my_comment_post_qb = $this->doctrine->em->createQueryBuilder();
        
        $my_event_admin_qb  = $this->doctrine->em->createQueryBuilder();
        $my_event_member_qb = $this->doctrine->em->createQueryBuilder();    
        

        // les publications sur lesquels l'utilisateur a liké

        $my_like_post_qb->select('p')
        ->from('Entity\Post', 'p')
        ->join('p.notifications', 'c', 'WITH', $qb->expr()->andX(
                $qb->expr()->eq('c.author', ':user'),
                $qb->expr()->eq('c.type', ':action_like')
                )
              );
        
        
        // les publications sur lesquels l'utilisateur a partagé
        
        $my_share_post_qb->select('p2')
        ->from('Entity\Post', 'p2')
        ->join('p2.notifications', 'c2', 'WITH', $qb->expr()->andX(
                $qb->expr()->eq('c2.author', ':user'),
                $qb->expr()->eq('c2.type', ':action_share')
                )
              );
        
        
        // les publications sur lesquels l'utilisateur a commenté
        
       $my_comment_post_qb->select('p3')
        ->from('Entity\Post', 'p3')
        ->join('p3.notifications', 'c3', 'WITH', $qb->expr()->andX(
                $qb->expr()->eq('c3.author', ':user'),
                $qb->expr()->eq('c3.type', ':action_comment')
                )
              );

       // les évènements que l'utilisateur administre

        $my_event_admin_qb->select('e')
                ->from('Entity\Event', 'e')
                ->join('e.admins', 'a1', 'WITH', $qb->expr()->eq('a1.id', ':user_id'));
        
        // les évènements dont j'ai donné une réponse positive (OUI, PEUT ETRE)
        
        $my_event_member_qb->select('e2')
                ->from('Entity\Event', 'e2')
                ->join('e2.participations', 'ep', 'WITH', $qb->expr()->andX(
                        $qb->expr()->eq('ep.user', ':user'),
                        $qb->expr()->orX(
                            $qb->expr()->eq('ep.statut', ':answer_yes'),
                            $qb->expr()->eq('ep.statut', ':answer_maybe')
                        )
                ));

        // les groupes dont l'utilisateur est membre
        
        if(!$user->is_a_group()){
            
            $my_group_member_qb = $this->doctrine->em->createQueryBuilder();
            
            $my_group_member_qb->select('g')
                ->from('Entity\User', 'g')
                ->join('g.group_members', 'gm', 'WITH', $qb->expr()->eq('gm.id', ':user_id'));
        }
        
        // --- FINAL QUERY


        $qb->from('Entity\Notification', 'n')
            //->select('n')
    // je suis la cible
            ->where($qb->expr()->andX(
                        $qb->expr()->eq('n.target_user', ':user'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
    // re-liké
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_post', $my_like_post_qb->getDQL()),
                        $qb->expr()->eq('n.type', ':action_like'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
    // re-sharé
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_post', $my_share_post_qb->getDQL()),
                        $qb->expr()->eq('n.type', ':action_share'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
    // re-comment
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_post', $my_comment_post_qb->getDQL()),
                        $qb->expr()->eq('n.type', ':action_comment'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
   
    // évènements admin     
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_event', $my_event_admin_qb->getDQL()),
                        $qb->expr()->neq('n.type', ':notif_join_request'),
                        $qb->expr()->neq('n.author', ':user')    
                    ))
            ->setParameter('notif_join_request', \Entity\Notification::JOIN_REQUEST)
    
    // évènements membre     
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_event', $my_event_member_qb->getDQL()),
                        $qb->expr()->orX(
                                $qb->expr()->eq('n.type', ':action_event_wall'),
                                $qb->expr()->eq('n.type', ':action_event_reminder')
                        ),
                        $qb->expr()->neq('n.author', ':user')    
                    ))
                    
    // messages privés
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->eq('n.target_user', ':user'),
                        $qb->expr()->eq('n.type', ':action_message'),
                        $qb->expr()->neq('n.author', ':user')
                    ));
        
    // pour les membres de groupe
        if(!$user->is_a_group()){
            
            $qb->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_user', $my_group_member_qb->getDQL()),
                        $qb->expr()->eq('n.type', ':action_on_wall'),
                        $qb->expr()->neq('n.author', ':user')
            ))
            ->setParameter('action_on_wall', \Entity\Notification::PUBLISH_ON_WALL);
        }

    // pour les groupes
       
        if($user->is_a_group()){
           
            $qb->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_group', ':user'),
                        $qb->expr()->neq('n.type', ':notif_join_request'),
                        $qb->expr()->neq('n.author', ':user')
            ))
            ->setParameter('notif_join_request', \Entity\Notification::JOIN_REQUEST);
        }
        
    // final
        
        $qb->setParameter('action_like', \Entity\Notification::LIKE)
            ->setParameter('action_share', \Entity\Notification::SHARE)
            ->setParameter('action_comment', \Entity\Notification::COMMENT)
            ->setParameter('action_message', \Entity\Notification::PUBLISH_MESSAGE)
            ->setParameter('action_event_wall', \Entity\Notification::PUBLISH_ON_EVENT)
            ->setParameter('action_event_reminder', \Entity\Notification::EVENT_REMINDER)     
            ->setParameter('user', $user)
            ->setParameter('user_id', $user->getId())
            ->setParameter('answer_yes', \Entity\EventJoin::ANSWER_YES)
            ->setParameter('answer_maybe', \Entity\EventJoin::ANSWER_MAYBE)
            ->orderBy('n.id', 'DESC');
        
        return $qb;
    }
    
    /**
     * Renvoie une Collection d'entité Notification représentant les dernières notifications non lues
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_notification($user){

        $qb = $this->get_base_query($user);       
        $qb->select('n')
           ->setMaxResults($this->by_page);

        $query = $qb->getQuery();      
        return $query->getResult();
    }

    /**
     * Renvoie une Collection d'entité Notification représentant les dernières notifications non lues
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_unread_notification($user){

        $qb = $this->get_base_query($user);
        
        $qb->select('n')
           ->andWhere($qb->expr()->gte('n.create_at', ':last_access'))
           ->setParameter('last_access', $user->getLast_notification_access());
        
        $query = $qb->getQuery();      
        return $query->getResult();
    }
    
    /**
     * Renvoie une Collection d'entité Notification représentant les dernières notifications déjà lues
     * @param \Entity\User $user
     * @param integer $last_id Plus récente Notification affichée (utilisé par l'API social/notification_list)
     * @param boolean $lt true: Less than, false: Greater than
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_already_read_notification($user, $last_id=0, $lt = true){

        $qb = $this->get_base_query($user);

        $qb->select('n')
           ->setMaxResults($this->by_page);
            // $user->getLast_notification_access()
        
        if($last_id>0){
            
            if($lt){
                $qb->andWhere($qb->expr()->lt('n.id', ':last_id'))
                                ->setParameter('last_id', $last_id);
            }else{
                $qb->andWhere($qb->expr()->gte('n.id', ':last_id'))
                            ->setParameter('last_id', $last_id);
            }
        }

        //if($page>0)   $qb->setFirstResult( ($page * $this->by_page) + 1 );
        
        $query = $qb->getQuery();
        
        //die($query->getSQL());
        
        return $query->getResult();
    }
    
    /**
     * Renvoie la Collection d'entité Notification de la semaine passée
     * Utilisé par le mail hebdomadaire (bilan des notifications)
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_notification_by_week($user){

        $qb = $this->get_base_query($user);
        
        // weekly
        
        $now            = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $sevenDaysAgo   = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $sevenDaysAgo->sub(new \DateInterval("P7D"));
        $last_access    = $user->getLast_notification_access();
        
        $from = ( $last_access > $sevenDaysAgo) ? $last_access : $sevenDaysAgo;

        //exit($now->format('d.m.Y').'/'.$sevenDaysAgo->format('d.m.Y'));
        
        $qb->select('n')
           ->andWhere($qb->expr()->between('n.create_at', ':from',':to'))
           ->setParameter('from', $from)
           ->setParameter('to', $now);
        
        $query = $qb->getQuery();
        return $query->getResult();
    }
    
    /**
     * Renvoie le nombre de notifications non lues
     * Détermine les notifications non lues grace à la date de dernier acces à la page Notification (user->last_notification_access)
     * La formule de selection des notifications est la suivante:
     * - Toutes les notifications
     * - de tout type (sauf PUBLISH_ON_WALL)
     * - qui ont pour cible
     * - un \Entity\Post ou un \Entity\Comment
     * - dont je suis propriétaire
     * - qui ont été créé après mon dernier accés à la page notification
     * - OU
     * - les notifications dont je suis le recepteur (notification->receiver)
     * - ordonnés par date de création (notification->create_at)
     * @param \Entity\User $user
     * @return integer Nombre de notifications
     */
    public function get_nb_notification($user){
        
        $qb = $this->get_base_query($user);

        $qb->select('COUNT(n.id)') // ***
           ->andWhere($qb->expr()->gt('n.create_at',':last_access')) // ***
           ->setParameter('last_access', $user->getLast_notification_access()); // ***
        
        
        $query = $qb->getQuery();
        return $query->getScalarResult()[0][1];
    }
}
