<?php

namespace Greensight\LaravelAuditing;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Greensight\LaravelAuditing\Contracts\AttributeEncoder;
use Greensight\LaravelAuditing\Contracts\AttributeRedactor;
use Greensight\LaravelAuditing\Contracts\IpAddressResolver;
use Greensight\LaravelAuditing\Contracts\UrlResolver;
use Greensight\LaravelAuditing\Contracts\UserAgentResolver;
use Greensight\LaravelAuditing\Contracts\UserResolver;
use Greensight\LaravelAuditing\Exceptions\AuditableTransitionException;
use Greensight\LaravelAuditing\Exceptions\AuditingException;

trait SupportsAudit
{
    /**
     * Auditable attributes excluded from the Audit.
     *
     * @var array
     */
    protected $excludedAttributes = [];

    /**
     * Audit event name.
     *
     * @var string
     */
    protected $auditEvent;

    /**
     * Is auditing disabled?
     *
     * @var bool
     */
    public static $auditingDisabled = false;

    /**
     * Auditable boot logic.
     *
     * @return void
     */
    public static function bootSupportsAudit()
    {
        if (!self::$auditingDisabled && static::isAuditingEnabled()) {
            static::observe(new AuditableObserver());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(
            Config::get('laravel-auditing.implementation', Models\Audit::class),
            'auditable'
        );
    }

    /**
     * Resolve the Auditable attributes to exclude from the Audit.
     *
     * @return void
     */
    protected function resolveAuditExclusions()
    {
        $this->excludedAttributes = $this->getAuditExclude();

        // When in strict mode, hidden and non visible attributes are excluded
        if ($this->getAuditStrict()) {
            // Hidden attributes
            $this->excludedAttributes = array_merge($this->excludedAttributes, $this->hidden);

            // Non visible attributes
            if ($this->visible) {
                $invisible = array_diff(array_keys($this->attributes), $this->visible);

                $this->excludedAttributes = array_merge($this->excludedAttributes, $invisible);
            }
        }

        // Exclude Timestamps
        if (!$this->getAuditTimestamps()) {
            array_push($this->excludedAttributes, $this->getCreatedAtColumn(), $this->getUpdatedAtColumn());

            if (in_array(SoftDeletes::class, class_uses_recursive(get_class($this)))) {
                $this->excludedAttributes[] = $this->getDeletedAtColumn();
            }
        }

        if (!in_array('id', $this->excludedAttributes)) {
            $this->excludedAttributes[] = 'id';
        }

        // Valid attributes are all those that made it out of the exclusion array
        $attributes = Arr::except($this->attributes, $this->excludedAttributes);

        foreach ($attributes as $attribute => $value) {
            // Apart from null, non scalar values will be excluded
            if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
                $this->excludedAttributes[] = $attribute;
            }
        }
    }

    /**
     * Get the old/new attributes of a retrieved event.
     *
     * @return array
     */
    protected function getRetrievedEventAttributes(): array
    {
        // This is a read event with no attribute changes,
        // only metadata will be stored in the Audit

        return [
            [],
            [],
            [],
        ];
    }

    /**
     * Get the old/new attributes of a created event.
     *
     * @return array
     */
    protected function getCreatedEventAttributes(): array
    {
        $new = [];

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $new[$attribute] = $value;
            }
        }

        return [
            [],
            $new,
            $new,
        ];
    }

    /**
     * Get the old/new attributes of an updated event.
     *
     * @return array
     */
    protected function getUpdatedEventAttributes(): array
    {
        $old = [];
        $new = [];
        $state = [];

        foreach ($this->getDirty() as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $old[$attribute] = Arr::get($this->original, $attribute);
                $new[$attribute] = Arr::get($this->attributes, $attribute);
            }
        }

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $state[$attribute] = $value;
            }
        }

        return [
            $old,
            $new,
            $state,
        ];
    }

    /**
     * Get the old/new attributes of a deleted event.
     *
     * @return array
     */
    protected function getDeletedEventAttributes(): array
    {
        $old = [];

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $old[$attribute] = $value;
            }
        }

        return [
            $old,
            [],
            $old,
        ];
    }

    /**
     * Get the old/new attributes of a restored event.
     *
     * @return array
     */
    protected function getRestoredEventAttributes(): array
    {
        [$old, $new, $state] = $this->getDeletedEventAttributes();

        return [
            $new,
            $old,
            $state,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function readyForAuditing(): bool
    {
        if (static::$auditingDisabled) {
            return false;
        }

        return $this->isEventAuditable($this->auditEvent);
    }

    /**
     * Modify attribute value.
     *
     * @param string $attribute
     * @param mixed  $value
     *
     * @throws AuditingException
     *
     * @return mixed
     */
    protected function modifyAttributeValue(string $attribute, $value)
    {
        $attributeModifiers = $this->getAttributeModifiers();

        if (!array_key_exists($attribute, $attributeModifiers)) {
            return $value;
        }

        $attributeModifier = $attributeModifiers[$attribute];

        if (is_subclass_of($attributeModifier, AttributeRedactor::class)) {
            return call_user_func([$attributeModifier, 'redact'], $value);
        }

        if (is_subclass_of($attributeModifier, AttributeEncoder::class)) {
            return call_user_func([$attributeModifier, 'encode'], $value);
        }

        throw new AuditingException(sprintf('Invalid AttributeModifier implementation: %s', $attributeModifier));
    }

    /**
     * {@inheritdoc}
     */
    public function toAudit(): array
    {
        if (!$this->readyForAuditing()) {
            throw new AuditingException('A valid audit event has not been set');
        }

        $attributeGetter = $this->resolveAttributeGetter($this->auditEvent);

        if (!method_exists($this, $attributeGetter)) {
            throw new AuditingException(sprintf(
                'Unable to handle "%s" event, %s() method missing',
                $this->auditEvent,
                $attributeGetter
            ));
        }

        $this->resolveAuditExclusions();

        [$old, $new, $state] = $this->$attributeGetter();

        if ($this->getAttributeModifiers()) {
            $old = $this->modifyAttributeValues($old);
            $new = $this->modifyAttributeValues($new);
            $state = $this->modifyAttributeValues($state);
        }

        $tags = implode(',', $this->generateTags());

        $user = $this->resolveUser();

        return $this->transformAudit([
            'old_values'         => $old,
            'new_values'         => $new,
            'state'              => $state,
            'event'              => $this->auditEvent,
            'auditable_id'       => $this->getKey(),
            'auditable_type'     => $this->getMorphClass(),
            'subject_id'         => $user ? $user->getAuthIdentifier() : null,
            'subject_type'       => $user ? $user->getMorphClass() : null,
            'url'                => $this->resolveUrl(),
            'ip_address'         => $this->resolveIpAddress(),
            'user_agent'         => $this->resolveUserAgent(),
            'tags'               => empty($tags) ? null : $tags,
        ]);
    }

    protected function modifyAttributeValues(array $source): array
    {
        $result = [];
        foreach ($source as $attribute => $value) {
            $result[$attribute] = $this->modifyAttributeValue($attribute, $value);
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function transformAudit(array $data): array
    {
        return $data;
    }

    /**
     * Resolve the User.
     *
     * @throws AuditingException
     *
     * @return mixed|null
     */
    protected function resolveUser()
    {
        $userResolver = Config::get('laravel-auditing.resolver.user');

        if (is_subclass_of($userResolver, UserResolver::class)) {
            return call_user_func([$userResolver, 'resolve']);
        }

        throw new AuditingException('Invalid UserResolver implementation');
    }

    /**
     * Resolve the URL.
     *
     * @throws AuditingException
     *
     * @return string
     */
    protected function resolveUrl(): string
    {
        $urlResolver = Config::get('laravel-auditing.resolver.url');

        if (is_subclass_of($urlResolver, UrlResolver::class)) {
            return call_user_func([$urlResolver, 'resolve']);
        }

        throw new AuditingException('Invalid UrlResolver implementation');
    }

    /**
     * Resolve the IP Address.
     *
     * @throws AuditingException
     *
     * @return string
     */
    protected function resolveIpAddress(): string
    {
        $ipAddressResolver = Config::get('laravel-auditing.resolver.ip_address');

        if (is_subclass_of($ipAddressResolver, IpAddressResolver::class)) {
            return call_user_func([$ipAddressResolver, 'resolve']);
        }

        throw new AuditingException('Invalid IpAddressResolver implementation');
    }

    /**
     * Resolve the User Agent.
     *
     * @throws AuditingException
     *
     * @return string|null
     */
    protected function resolveUserAgent()
    {
        $userAgentResolver = Config::get('laravel-auditing.resolver.user_agent');

        if (is_subclass_of($userAgentResolver, UserAgentResolver::class)) {
            return call_user_func([$userAgentResolver, 'resolve']);
        }

        throw new AuditingException('Invalid UserAgentResolver implementation');
    }

    /**
     * Determine if an attribute is eligible for auditing.
     *
     * @param string $attribute
     *
     * @return bool
     */
    protected function isAttributeAuditable(string $attribute): bool
    {
        // The attribute should not be audited
        if (in_array($attribute, $this->excludedAttributes, true)) {
            return false;
        }

        // The attribute is auditable when explicitly
        // listed or when the include array is empty
        $include = $this->getAuditInclude();

        return empty($include) || in_array($attribute, $include, true);
    }

    /**
     * Determine whether an event is auditable.
     *
     * @param string $event
     *
     * @return bool
     */
    protected function isEventAuditable($event): bool
    {
        return is_string($this->resolveAttributeGetter($event));
    }

    /**
     * Attribute getter method resolver.
     *
     * @param string $event
     *
     * @return string|null
     */
    protected function resolveAttributeGetter($event)
    {
        foreach ($this->getAuditEvents() as $key => $value) {
            $auditableEvent = is_int($key) ? $value : $key;

            $auditableEventRegex = sprintf('/%s/', preg_replace('/\*+/', '.*', $auditableEvent));

            if (preg_match($auditableEventRegex, $event)) {
                return is_int($key) ? sprintf('get%sEventAttributes', ucfirst($event)) : $value;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setAuditEvent(string $event): Contracts\Auditable
    {
        $this->auditEvent = $this->isEventAuditable($event) ? $event : null;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditEvent()
    {
        return $this->auditEvent;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditEvents(): array
    {
        return $this->auditEvents ?? Config::get('laravel-auditing.events', [
            'created',
            'updated',
            'deleted',
            'restored',
        ]);
    }

    /**
     * Disable Auditing.
     *
     * @return void
     */
    public static function disableAuditing()
    {
        static::$auditingDisabled = true;
    }

    /**
     * Enable Auditing.
     *
     * @return void
     */
    public static function enableAuditing()
    {
        static::$auditingDisabled = false;
    }

    /**
     * Determine whether auditing is enabled.
     *
     * @return bool
     */
    public static function isAuditingEnabled(): bool
    {
        if (App::runningInConsole()) {
            return Config::get('laravel-auditing.console', false);
        }

        return Config::get('laravel-auditing.enabled', true);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditInclude(): array
    {
        return $this->auditInclude ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditExclude(): array
    {
        return $this->auditExclude ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditStrict(): bool
    {
        return $this->auditStrict ?? Config::get('laravel-auditing.strict', false);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditTimestamps(): bool
    {
        return $this->auditTimestamps ?? Config::get('laravel-auditing.timestamps', false);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditDriver()
    {
        return $this->auditDriver ?? Config::get('laravel-auditing.driver', 'database');
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditThreshold(): int
    {
        return $this->auditThreshold ?? Config::get('laravel-auditing.threshold', 0);
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributeModifiers(): array
    {
        return $this->attributeModifiers ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function generateTags(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function transitionTo(Contracts\Audit $audit, bool $old = false): Contracts\Auditable
    {
        // The Audit must be for an Auditable model of this type
        if ($this->getMorphClass() !== $audit->auditable_type) {
            throw new AuditableTransitionException(sprintf(
                'Expected Auditable type %s, got %s instead',
                $this->getMorphClass(),
                $audit->auditable_type
            ));
        }

        // The Audit must be for this specific Auditable model
        if ($this->getKey() !== $audit->auditable_id) {
            throw new AuditableTransitionException(sprintf(
                'Expected Auditable id %s, got %s instead',
                $this->getKey(),
                $audit->auditable_id
            ));
        }

        // Redacted data should not be used when transitioning states
        foreach ($this->getAttributeModifiers() as $attribute => $modifier) {
            if (is_subclass_of($modifier, AttributeRedactor::class)) {
                throw new AuditableTransitionException('Cannot transition states when an AttributeRedactor is set');
            }
        }

        // The attribute compatibility between the Audit and the Auditable model must be met
        $modified = $audit->getModified();

        if ($incompatibilities = array_diff_key($modified, $this->getAttributes())) {
            throw new AuditableTransitionException(sprintf(
                'Incompatibility between [%s:%s] and [%s:%s]',
                $this->getMorphClass(),
                $this->getKey(),
                get_class($audit),
                $audit->getKey()
            ), array_keys($incompatibilities));
        }

        $key = $old ? 'old' : 'new';

        foreach ($modified as $attribute => $value) {
            if (array_key_exists($key, $value)) {
                $this->setAttribute($attribute, $value[$key]);
            }
        }

        return $this;
    }
}
