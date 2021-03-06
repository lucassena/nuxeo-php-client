<?php
/**
 * (C) Copyright 2018 Nuxeo SA (http://nuxeo.com/) and contributors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Nuxeo\Client\Marshaller;


use Doctrine\Common\Annotations\Reader;
use JMS\Serializer\Construction\UnserializeObjectConstructor;
use JMS\Serializer\DeserializationContext;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\GraphNavigator;
use JMS\Serializer\Handler\DateHandler;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\JsonDeserializationVisitor;
use JMS\Serializer\Metadata\Driver\AnnotationDriver;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\Naming\SerializedNameAnnotationStrategy;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\TypeParser;
use JMS\Serializer\VisitorInterface;
use Metadata\MetadataFactory;
use Metadata\MetadataFactoryInterface;
use Nuxeo\Client\Objects\Document;
use Nuxeo\Client\Objects\Documents;
use Nuxeo\Client\Spi\NuxeoClientException;
use Nuxeo\Client\Spi\Serializer\JsonSerializationVisitor;

class NuxeoConverter {

  /**
   * @var NuxeoMarshaller[]
   */
  protected $marshallers = array();

  /**
   * @var PropertyNamingStrategyInterface
   */
  private $strategy;

  /**
   * @var VisitorInterface
   */
  private $serializationVisitor;

  /**
   * @var VisitorInterface
   */
  private $deserializationVisitor;

  /**
   * @var MetadataFactoryInterface
   */
  private $metadataFactory;

  /**
   * @var GraphNavigator
   */
  private $graphNavigator;

  /**
   * @var Reader
   */
  private $annotationReader;

  /**
   * NuxeoConverter constructor.
   * @param Reader $annotationReader
   */
  public function __construct(Reader $annotationReader) {
    $this->annotationReader = $annotationReader;
  }

  /**
   * @param string $type
   * @param NuxeoMarshaller $marshaller
   * @return NuxeoConverter
   */
  public function registerMarshaller($type, $marshaller) {
    $this->marshallers[$type] = $marshaller;
    return $this;
  }

  /**
   * @return NuxeoMarshaller[]
   */
  public function getMarshallers() {
    return $this->marshallers;
  }

  /**
   * @param string $type
   * @return NuxeoMarshaller
   */
  public function getMarshaller($type) {
    return $this->marshallers[$type];
  }

  /**
   * @param mixed $object
   * @return string
   */
  public function writeJSON($object) {
    $context = new SerializationContext();

    $context->initialize(
      'json',
      $visitor = $this->getSerializationVisitor(),
      $navigator =$this->getGraphNavigator(),
      $this->getMetadataFactory()
    );

    $visitor->setNavigator($navigator);
    $navigator->accept($visitor->prepare($object), null, $context);

    return $visitor->getResult();
  }

  /**
   * @param string $data
   * @param string $type
   * @return mixed
   * @throws NuxeoClientException
   */
  public function readJSON($data, $type = null) {
    $visitor = $this->getDeserializationVisitor();
    $navigator = $this->getGraphNavigator();

    $visitor->setNavigator($navigator);
    $array_data = $visitor->prepare($data);

    if(null === $type) {
      $type = 'array';

      if(array_key_exists('entity-type', $array_data)) {
        $entityType = $array_data['entity-type'];
        $typeMap = array(
          'document' => Document::class,
          'documents' => Documents::class,
        );

        if(array_key_exists($entityType, $typeMap)) {
          $type = $typeMap[$entityType];
        }
      }
    }

    return $this->readData($array_data, $type);
  }

  /**
   * @param string|array $data
   * @param string $type
   * @return mixed
   */
  public function readData($data, $type) {
    $context = new DeserializationContext();
    $typeParser = new TypeParser();

    $context->initialize(
      'json',
      $visitor = $this->getDeserializationVisitor(),
      $navigator = $this->getGraphNavigator(),
      $this->getMetadataFactory()
    );

    $visitor->setNavigator($navigator);

    return $navigator->accept($data, $typeParser->parse($type), $context);
  }

  protected function getPropertyNamingStrategy() {
    if(null === $this->strategy) {
      $this->strategy = new SerializedNameAnnotationStrategy(new IdenticalPropertyNamingStrategy());
    }
    return $this->strategy;
  }

  /**
   * @return VisitorInterface
   */
  protected function getSerializationVisitor() {
    if(null === $this->serializationVisitor) {
      $this->serializationVisitor = new JsonSerializationVisitor($this->getPropertyNamingStrategy());
    }
    return $this->serializationVisitor;
  }

  /**
   * @return VisitorInterface
   */
  protected function getDeserializationVisitor() {
    if(null === $this->deserializationVisitor) {
      $this->deserializationVisitor = new JsonDeserializationVisitor($this->getPropertyNamingStrategy());
    }
    return $this->deserializationVisitor;
  }

  /**
   * @return Reader
   */
  protected function getAnnotationReader() {
    return $this->annotationReader;
  }

  /**
   * @return MetadataFactoryInterface
   */
  protected function getMetadataFactory() {
    if(null === $this->metadataFactory) {
      $this->metadataFactory = new MetadataFactory(new AnnotationDriver($this->getAnnotationReader()), null, false);
    }
    return $this->metadataFactory;
  }

  /**
   * @return GraphNavigator
   */
  protected function getGraphNavigator() {
    if(null === $this->graphNavigator) {
      $this->graphNavigator = new GraphNavigator(
        $this->getMetadataFactory(),
        $registry = new HandlerRegistry(),
        new UnserializeObjectConstructor(),
        new EventDispatcher()
      );

      $self = $this;

      $registry->registerSubscribingHandler(new DateHandler());
      foreach($this->getMarshallers() as $type => $marshaller) {
        $registry->registerHandler(
          GraphNavigator::DIRECTION_SERIALIZATION,
          $type,
          'json',
          function(VisitorInterface $visitor, $object, array $type, SerializationContext $context) use ($self) {
            $marshaller = $self->getMarshaller($type['name']);
            return $marshaller->write($object, $visitor, $context);
          }
        );
        $registry->registerHandler(
          GraphNavigator::DIRECTION_DESERIALIZATION,
          $type,
          'json',
          function(VisitorInterface $visitor, $object, array $type, DeserializationContext $context) use ($self) {
            $marshaller = $self->getMarshaller($type['name']);
            return $marshaller->read($object, $visitor, $context);
          }
        );
      }
    }
    return $this->graphNavigator;
  }

}
